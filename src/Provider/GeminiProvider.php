<?php

namespace Ernestdefoe\Phrasebook\Provider;

use Ernestdefoe\Phrasebook\Net\Http;
use Ernestdefoe\Phrasebook\Net\Refused;

/** Google Gemini, through the Generative Language API, in JSON mode. */
final class GeminiProvider implements Provider
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(private readonly Http $http, private readonly string $key)
    {
    }

    public function models(): array
    {
        $data = $this->call('GET', '/models?pageSize=1000', null);
        $models = [];

        foreach ($data['models'] ?? [] as $model) {
            if (! is_array($model) || ! in_array('generateContent', (array) ($model['supportedGenerationMethods'] ?? []), true)) {
                continue;
            }

            $id = preg_replace('~^models/~', '', (string) ($model['name'] ?? ''));

            if ($id !== '' && $id !== null) {
                $models[] = ['id' => $id, 'label' => (string) ($model['displayName'] ?? $id)];
            }
        }

        return $models;
    }

    public function translate(Job $job): array
    {
        // The model id goes into the URL path: nothing but a model name.
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $job->model)) {
            throw new ProviderError('model', 0, null, 'Not a model name.');
        }

        $body = $this->call('POST', '/models/'.$job->model.':generateContent', [
            'systemInstruction' => ['parts' => [['text' => Prompt::system($job)]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => Prompt::user($job)]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'maxOutputTokens' => $job->maxTokens,
            ],
        ]);

        $meta = $body['usageMetadata'] ?? [];
        $spent = new Usage(
            input: (int) ($meta['promptTokenCount'] ?? 0),
            // Thinking is billed as output.
            output: (int) ($meta['candidatesTokenCount'] ?? 0) + (int) ($meta['thoughtsTokenCount'] ?? 0),
            cacheRead: (int) ($meta['cachedContentTokenCount'] ?? 0),
            model: (string) ($body['modelVersion'] ?? $job->model)
        );

        if (isset($body['promptFeedback']['blockReason'])) {
            throw new BatchFailed(new ProviderError('refused', 200, null, 'The model declined this batch.'), $spent);
        }

        $candidate = $body['candidates'][0] ?? [];
        $finish = (string) ($candidate['finishReason'] ?? '');

        if ($finish === 'MAX_TOKENS') {
            throw new BatchFailed(new ProviderError('too_large', 200, null, 'The reply was cut off at the output limit.'), $spent);
        }

        if (in_array($finish, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'], true)) {
            throw new BatchFailed(new ProviderError('refused', 200, null, 'The model declined this batch.'), $spent);
        }

        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (is_array($part) && empty($part['thought'])) {
                $text .= (string) ($part['text'] ?? '');
            }
        }

        try {
            return ['translations' => Prompt::parse($text, $job), 'usage' => $spent];
        } catch (ProviderError $e) {
            throw new BatchFailed($e, $spent);
        }
    }

    public function finish(Job $job): void
    {
    }

    /**
     * @param array<mixed>|null $payload
     *
     * @return array<mixed>
     */
    private function call(string $method, string $path, ?array $payload): array
    {
        try {
            $response = $this->http->request($method, self::BASE.$path, ['x-goog-api-key' => $this->key], $payload, false, 300);
        } catch (Refused $e) {
            throw new ProviderError('network', 0, null, $e->reason);
        }

        if ($response->status < 200 || $response->status >= 300) {
            $error = Prompt::error($response);

            // Google answers a bad key with 400 API_KEY_INVALID.
            if ($response->status === 400 && str_contains((string) json_encode($response->json()), 'API_KEY_INVALID')) {
                throw new ProviderError('auth', 400, null, $error->detail);
            }

            throw $error;
        }

        return $response->json() ?? throw new ProviderError('invalid_response', $response->status);
    }
}
