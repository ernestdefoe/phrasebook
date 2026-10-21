<?php

namespace Ernestdefoe\Phrasebook\Provider;

use Ernestdefoe\Phrasebook\Net\Http;
use Ernestdefoe\Phrasebook\Net\Refused;

/**
 * Every provider that speaks OpenAI's chat completions: OpenAI itself,
 * Mistral, DeepSeek, Groq, OpenRouter, Ollama, LM Studio and any compatible
 * endpoint. JSON mode is asked for; a server that doesn't know it (LM Studio,
 * some OpenRouter models) is asked once more without it, and the reply is
 * parsed leniently either way.
 */
final class OpenAiProvider implements Provider
{
    /** Model ids in OpenAI's list that can't chat. */
    private const NOT_CHAT = '/(embed|tts|whisper|dall-e|gpt-image|image|audio|realtime|transcribe|moderation|search|computer-use|babbage|davinci|sora)/i';

    private bool $jsonMode = true;

    public function __construct(
        private readonly Http $http,
        private readonly string $id,
        private readonly string $base,
        #[\SensitiveParameter] private readonly ?string $key,
        private readonly bool $allowLocal
    ) {
    }

    public function models(): array
    {
        $data = $this->call('GET', '/models', null);
        $models = [];

        foreach ($data['data'] ?? $data['models'] ?? [] as $model) {
            $id = is_array($model) ? ($model['id'] ?? $model['name'] ?? null) : null;

            if (! is_string($id) || ($this->id === 'openai' && preg_match(self::NOT_CHAT, $id))) {
                continue;
            }

            $row = ['id' => $id, 'label' => (string) ($model['name'] ?? $id)];

            // OpenRouter lists its prices: USD per token, as strings.
            $pricing = $model['pricing'] ?? null;
            if (is_array($pricing) && is_numeric($pricing['prompt'] ?? null) && is_numeric($pricing['completion'] ?? null)) {
                $row['price'] = ['in' => round((float) $pricing['prompt'] * 1e6, 6), 'out' => round((float) $pricing['completion'] * 1e6, 6)];
            }

            $models[] = $row;
        }

        usort($models, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return $models;
    }

    public function translate(Job $job): array
    {
        $payload = [
            'model' => $job->model,
            'messages' => [
                ['role' => 'system', 'content' => Prompt::system($job)],
                ['role' => 'user', 'content' => Prompt::user($job)],
            ],
        ];

        // OpenAI's current models take max_completion_tokens and refuse
        // max_tokens; the compatible servers mostly know only max_tokens.
        if ($this->id === 'openai') {
            $payload['max_completion_tokens'] = $job->maxTokens;
            if (preg_match('/^(o\d|gpt-5)/', $job->model)) {
                $payload['reasoning_effort'] = $job->effort;
            }
        } else {
            $payload['max_tokens'] = $job->maxTokens;
        }

        if ($this->id === 'openrouter') {
            $payload['usage'] = ['include' => true];
        }

        if ($this->jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $body = $this->call('POST', '/chat/completions', $payload);
        } catch (ProviderError $e) {
            if (! $this->jsonMode || $e->kind !== 'bad_request' || ! preg_match('/response_format|json/i', $e->detail)) {
                throw $e;
            }
            $this->jsonMode = false;
            unset($payload['response_format']);
            $body = $this->call('POST', '/chat/completions', $payload);
        }

        $usage = $body['usage'] ?? [];
        // OpenAI counts cached tokens inside prompt_tokens: split them out so
        // they are neither charged twice nor shown twice.
        $cached = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
        $spent = new Usage(
            input: max(0, (int) ($usage['prompt_tokens'] ?? 0) - $cached),
            output: (int) ($usage['completion_tokens'] ?? 0),
            cacheRead: $cached,
            cost: is_numeric($usage['cost'] ?? null) ? (float) $usage['cost'] : null,
            model: (string) ($body['model'] ?? $job->model)
        );

        $choice = $body['choices'][0] ?? [];
        $finish = (string) ($choice['finish_reason'] ?? '');

        if ($finish === 'length') {
            throw new BatchFailed(new ProviderError('too_large', 200, null, 'The reply was cut off at the output limit.'), $spent);
        }

        if ($finish === 'content_filter' || ! empty($choice['message']['refusal'])) {
            throw new BatchFailed(new ProviderError('refused', 200, null, 'The model declined this batch.'), $spent);
        }

        $content = $choice['message']['content'] ?? '';
        if (is_array($content)) {
            $content = implode('', array_map(fn ($part) => is_array($part) ? (string) ($part['text'] ?? '') : '', $content));
        }

        // Reasoning models served through compatible APIs sometimes put
        // their thinking in <think> before the answer.
        $content = preg_replace('~^\s*<think>.*?</think>~s', '', (string) $content) ?? '';

        try {
            return ['translations' => Prompt::parse($content, $job), 'usage' => $spent];
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
        if ($this->base === '') {
            throw new ProviderError('config', 0, null, 'No base URL is set.');
        }

        $headers = [];
        if ($this->key !== null && $this->key !== '') {
            $headers['Authorization'] = 'Bearer '.$this->key;
        }
        if ($this->id === 'openrouter') {
            $headers['X-Title'] = 'Phrasebook for Flarum';
        }

        // Local models are slow on modest hardware: give them longer.
        $timeout = in_array($this->id, ['ollama', 'lmstudio', 'custom'], true) ? 600 : 300;

        try {
            $response = $this->http->request($method, rtrim($this->base, '/').$path, $headers, $payload, $this->allowLocal, $timeout);
        } catch (Refused $e) {
            throw new ProviderError(in_array($e->reason, ['timeout', 'connect', 'dns', 'too_large'], true) ? 'network' : 'config', 0, null, $e->reason);
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw Prompt::error($response);
        }

        return $response->json() ?? throw new ProviderError('invalid_response', $response->status);
    }
}
