<?php

namespace Ernestdefoe\Phrasebook\Provider;

use Ernestdefoe\Phrasebook\Net\Http;
use Ernestdefoe\Phrasebook\Net\Refused;

/**
 * Claude, through the Messages API. Structured outputs guarantee the reply
 * parses, effort keeps thinking short (translation needs little of it), the
 * system prompt is cached across a run's batches, and on the models that
 * can decline a request a server-side fallback answers instead.
 */
final class AnthropicProvider implements Provider
{
    /** Models that take output_config.format (structured outputs). */
    private const STRUCTURED = '/^claude-(fable|mythos|opus-5|opus-4-[5-9]|sonnet-5|sonnet-4-[5-9]|haiku-5|haiku-4-5)/';

    /** Models that take output_config.effort. */
    private const EFFORT = '/^claude-(fable|mythos|opus-5|opus-4-[5-9]|sonnet-5|sonnet-4-6|haiku-5)/';

    /** Models whose classifiers can decline; a fallback model answers instead. */
    private const FALLBACK = '/^claude-(fable-5-1|opus-5|sonnet-5-5)/';

    public function __construct(private readonly Http $http, #[\SensitiveParameter] private readonly string $key)
    {
    }

    public function models(): array
    {
        $data = $this->call('GET', '/v1/models?limit=1000', null);
        $models = [];

        foreach ($data['data'] ?? [] as $model) {
            if (is_array($model) && is_string($model['id'] ?? null)) {
                $models[] = ['id' => $model['id'], 'label' => (string) ($model['display_name'] ?? $model['id'])];
            }
        }

        return $models;
    }

    public function translate(Job $job): array
    {
        $payload = [
            'model' => $job->model,
            'max_tokens' => $job->maxTokens,
            'system' => [['type' => 'text', 'text' => Prompt::system($job), 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => Prompt::user($job)]],
        ];

        $config = [];
        if (preg_match(self::STRUCTURED, $job->model)) {
            $config['format'] = ['type' => 'json_schema', 'schema' => Prompt::SCHEMA];
        }
        if (preg_match(self::EFFORT, $job->model)) {
            $config['effort'] = $job->effort;
        }
        if ($config) {
            $payload['output_config'] = $config;
        }

        $beta = null;
        if (preg_match(self::FALLBACK, $job->model)) {
            $payload['fallbacks'] = 'default';
            $beta = 'server-side-fallback-2026-07-01';
        }

        $body = $this->call('POST', '/v1/messages', $payload, $beta);

        $stop = (string) ($body['stop_reason'] ?? '');
        $usage = $body['usage'] ?? [];
        $spent = new Usage(
            input: (int) ($usage['input_tokens'] ?? 0),
            output: (int) ($usage['output_tokens'] ?? 0),
            cacheWrite: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            cacheRead: (int) ($usage['cache_read_input_tokens'] ?? 0),
            model: (string) ($body['model'] ?? $job->model)
        );

        if ($stop === 'refusal') {
            throw new BatchFailed(new ProviderError('refused', 200, null, 'The model declined this batch.'), $spent);
        }

        if ($stop === 'max_tokens') {
            throw new BatchFailed(new ProviderError('too_large', 200, null, 'The reply was cut off at the output limit.'), $spent);
        }

        $text = '';
        foreach ($body['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
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
    private function call(string $method, string $path, ?array $payload, ?string $beta = null): array
    {
        $headers = ['x-api-key' => $this->key, 'anthropic-version' => '2023-06-01'];
        if ($beta) {
            $headers['anthropic-beta'] = $beta;
        }

        try {
            $response = $this->http->request($method, 'https://api.anthropic.com'.$path, $headers, $payload, false, 300);
        } catch (Refused $e) {
            throw new ProviderError('network', 0, null, $e->reason);
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw Prompt::error($response);
        }

        return $response->json() ?? throw new ProviderError('invalid_response', $response->status);
    }
}
