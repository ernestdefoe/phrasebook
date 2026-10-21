<?php

namespace Ernestdefoe\Phrasebook\Provider;

use Ernestdefoe\Phrasebook\Icu\Segmenter;
use Ernestdefoe\Phrasebook\Net\Http;
use Ernestdefoe\Phrasebook\Net\Refused;

/**
 * DeepL and Google Cloud Translation. These engines translate text, not ICU,
 * so each string goes through the Segmenter: placeholders and plural/select
 * syntax are hidden as <x i="N"/> tags the engine leaves alone, and the
 * pieces are put back together afterwards.
 */
abstract class MachineTranslation implements Provider
{
    /** Texts per request the API accepts. */
    protected const PER_REQUEST = 50;

    /** Characters per request, kept well under both APIs' limits. */
    protected const CHARS_PER_REQUEST = 20000;

    public function __construct(protected readonly Http $http, protected readonly string $key)
    {
    }

    /**
     * @param list<string> $texts
     *
     * @return list<string> one per text, in order
     */
    abstract protected function send(array $texts, Job $job): array;

    public function translate(Job $job): array
    {
        $plans = [];
        $texts = [];

        foreach ($job->items as $id => $item) {
            $split = Segmenter::split($item['text']);

            // Not valid ICU in English: left for the admin to translate by hand.
            if ($split === null) {
                continue;
            }

            $plans[$id] = [$split['plan'], count($texts), count($split['units'])];
            array_push($texts, ...$split['units']);
        }

        $usage = new Usage(characters: array_sum(array_map('mb_strlen', $texts)), model: $job->model);
        $out = [];

        // Request by request, within the API's limits.
        $chunk = [];
        $size = 0;
        foreach ($texts as $text) {
            if ($chunk && (count($chunk) >= static::PER_REQUEST || $size + mb_strlen($text) > static::CHARS_PER_REQUEST)) {
                array_push($out, ...$this->send($chunk, $job));
                $chunk = [];
                $size = 0;
            }
            $chunk[] = $text;
            $size += mb_strlen($text);
        }
        if ($chunk) {
            array_push($out, ...$this->send($chunk, $job));
        }

        if (count($out) !== count($texts)) {
            throw new BatchFailed(new ProviderError('invalid_response', 200, null, 'The engine returned a different number of texts.'), $usage);
        }

        $translations = [];
        foreach ($plans as $id => [$plan, $from, $count]) {
            $joined = $plan->join(array_slice($out, $from, $count));
            if ($joined !== null) {
                $translations[(string) $id] = $joined;
            }
        }

        return ['translations' => $translations, 'usage' => $usage];
    }

    public function finish(Job $job): void
    {
    }

    /**
     * @param array<string, string> $headers
     * @param array<mixed>|null $payload
     *
     * @return array<mixed>
     */
    protected function call(string $method, string $url, array $headers, ?array $payload): array
    {
        try {
            $response = $this->http->request($method, $url, $headers, $payload, false, 120);
        } catch (Refused $e) {
            throw new ProviderError('network', 0, null, $e->reason);
        }

        if ($response->status === 204) {
            return [];
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw Prompt::error($response);
        }

        return $response->json() ?? throw new ProviderError('invalid_response', $response->status);
    }
}
