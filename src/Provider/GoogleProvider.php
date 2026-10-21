<?php

namespace Ernestdefoe\Phrasebook\Provider;

use Ernestdefoe\Phrasebook\Strings\Languages;

/**
 * Google Cloud Translation (Basic, v2), with the key in a header rather than
 * the URL. HTML mode keeps the <x/> placeholders and the message's tags.
 * Basic has no glossaries and no formality setting.
 */
final class GoogleProvider extends MachineTranslation
{
    protected const PER_REQUEST = 100;

    public const MODELS = ['nmt', 'base'];

    public function models(): array
    {
        return array_map(fn ($id) => ['id' => $id, 'label' => $id], self::MODELS);
    }

    protected function send(array $texts, Job $job): array
    {
        $payload = [
            'q' => $texts,
            'source' => 'en',
            'target' => Languages::google($job->locale),
            'format' => 'html',
        ];

        if (in_array($job->model, self::MODELS, true)) {
            $payload['model'] = $job->model;
        }

        $data = $this->call('POST', 'https://translation.googleapis.com/language/translate/v2', ['X-Goog-Api-Key' => $this->key], $payload);

        return array_map(fn ($row) => is_array($row) ? (string) ($row['translatedText'] ?? '') : '', $data['data']['translations'] ?? []);
    }
}
