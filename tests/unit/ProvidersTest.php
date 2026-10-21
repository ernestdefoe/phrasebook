<?php

namespace Ernestdefoe\Phrasebook\Tests\unit;

use Ernestdefoe\Phrasebook\Provider\AnthropicProvider;
use Ernestdefoe\Phrasebook\Provider\BatchFailed;
use Ernestdefoe\Phrasebook\Provider\DeepLProvider;
use Ernestdefoe\Phrasebook\Provider\GeminiProvider;
use Ernestdefoe\Phrasebook\Provider\GoogleProvider;
use Ernestdefoe\Phrasebook\Provider\Job;
use Ernestdefoe\Phrasebook\Provider\OpenAiProvider;
use Ernestdefoe\Phrasebook\Provider\Pricing;
use Ernestdefoe\Phrasebook\Provider\Prompt;
use Ernestdefoe\Phrasebook\Provider\ProviderError;
use Ernestdefoe\Phrasebook\Provider\Usage;
use Ernestdefoe\Phrasebook\Strings\Languages;
use PHPUnit\Framework\TestCase;

class ProvidersTest extends TestCase
{
    private function job(string $model, array $glossary = [], string $tone = 'neutral'): Job
    {
        return new Job(
            ['7' => ['key' => 'core.forum.x', 'text' => '{count, plural, one {# reply} other {# replies}}', 'feedback' => null],
             '8' => ['key' => 'core.forum.y', 'text' => 'Reply', 'feedback' => 'Your reply had no translation for this id.']],
            'de',
            'German',
            $model,
            $tone,
            'Use du.',
            $glossary,
            4096,
        );
    }

    private function reply(): string
    {
        return (string) json_encode(['translations' => [
            ['id' => '7', 'text' => '{count, plural, one {# Antwort} other {# Antworten}}'],
            ['id' => '8', 'text' => 'Antworten'],
            ['id' => '99', 'text' => 'not asked for'],
        ]]);
    }

    public function test_anthropic_request_and_reply(): void
    {
        $http = (new FakeHttp())->answer(200, [
            'model' => 'claude-opus-5-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => $this->reply()]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'cache_creation_input_tokens' => 10, 'cache_read_input_tokens' => 20],
        ]);

        $result = (new AnthropicProvider($http, 'sk-ant-secret'))->translate($this->job('claude-opus-5-5'));

        $sent = $http->sent[0];
        $this->assertSame('https://api.anthropic.com:443/v1/messages', $sent['target']['url']);
        $this->assertSame('sk-ant-secret', $sent['headers']['x-api-key']);
        $this->assertSame('2023-06-01', $sent['headers']['anthropic-version']);
        $this->assertSame('server-side-fallback-2026-07-01', $sent['headers']['anthropic-beta']);
        $this->assertSame('default', $sent['body']['fallbacks']);
        $this->assertSame('json_schema', $sent['body']['output_config']['format']['type']);
        $this->assertSame('low', $sent['body']['output_config']['effort']);
        $this->assertArrayNotHasKey('thinking', $sent['body']);
        $this->assertArrayNotHasKey('temperature', $sent['body']);
        $this->assertSame(['type' => 'ephemeral'], $sent['body']['system'][0]['cache_control']);
        $this->assertStringContainsString('German', $sent['body']['system'][0]['text']);

        $this->assertSame(['7' => '{count, plural, one {# Antwort} other {# Antworten}}', '8' => 'Antworten'], $result['translations']);
        $this->assertSame(100, $result['usage']->input);
        $this->assertSame(10, $result['usage']->cacheWrite);
    }

    public function test_anthropic_older_model_gets_no_new_parameters(): void
    {
        $http = (new FakeHttp())->answer(200, ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $this->reply()]], 'usage' => []]);
        (new AnthropicProvider($http, 'k'))->translate($this->job('claude-3-5-haiku-latest'));

        $body = $http->sent[0]['body'];
        $this->assertArrayNotHasKey('output_config', $body);
        $this->assertArrayNotHasKey('fallbacks', $body);
        $this->assertArrayNotHasKey('anthropic-beta', $http->sent[0]['headers']);
    }

    public function test_anthropic_refusal_and_truncation_are_billed_failures(): void
    {
        foreach (['refusal' => 'refused', 'max_tokens' => 'too_large'] as $stop => $kind) {
            $http = (new FakeHttp())->answer(200, ['stop_reason' => $stop, 'content' => [], 'usage' => ['input_tokens' => 5, 'output_tokens' => 3]]);

            try {
                (new AnthropicProvider($http, 'k'))->translate($this->job('claude-sonnet-5-5'));
                $this->fail('no exception');
            } catch (BatchFailed $e) {
                $this->assertSame($kind, $e->error->kind);
                $this->assertSame(3, $e->usage->output);
            }
        }
    }

    public function test_http_errors_become_provider_errors(): void
    {
        $cases = [
            [401, [], 'auth'], [403, [], 'auth'], [402, [], 'quota'], [404, [], 'model'],
            [429, ['retry-after' => '7'], 'rate_limit'], [529, [], 'overloaded'], [500, [], 'server'], [400, [], 'bad_request'],
        ];

        foreach ($cases as [$status, $headers, $kind]) {
            $http = (new FakeHttp())->answer($status, ['error' => ['message' => 'nope sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123456789']], $headers);

            try {
                (new AnthropicProvider($http, 'k'))->translate($this->job('claude-opus-5-5'));
                $this->fail('no exception');
            } catch (ProviderError $e) {
                $this->assertSame($kind, $e->kind, "status $status");
                $this->assertStringNotContainsString('abcdefghijklmnop', $e->detail, 'a key in an error message is masked');
                if ($status === 429) {
                    $this->assertSame(7, $e->retryAfter);
                    $this->assertTrue($e->retryable());
                }
            }
        }
    }

    public function test_openai_compatible_request_reply_and_json_mode_fallback(): void
    {
        $http = (new FakeHttp())
            ->answer(400, ['error' => ['message' => "'response_format.type' must be 'json_schema' or 'text'"]])
            ->answer(200, ['model' => 'qwen', 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => "<think>hmm</think>```json\n".$this->reply()."\n```"]]], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 22]]);

        $provider = new OpenAiProvider($http, 'lmstudio', 'http://127.0.0.1:1234/v1', null, true);
        $result = $provider->translate($this->job('qwen'));

        $this->assertSame('json_object', $http->sent[0]['body']['response_format']['type']);
        $this->assertArrayNotHasKey('response_format', $http->sent[1]['body']);
        $this->assertArrayNotHasKey('Authorization', $http->sent[1]['headers']);
        $this->assertSame(4096, $http->sent[1]['body']['max_tokens']);
        $this->assertSame('Antworten', $result['translations']['8']);
        $this->assertSame(22, $result['usage']->output);
    }

    public function test_openai_uses_its_current_parameters(): void
    {
        $http = (new FakeHttp())->answer(200, ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $this->reply()]]], 'usage' => []]);
        (new OpenAiProvider($http, 'openai', 'https://api.openai.com/v1', 'sk-x', false))->translate($this->job('gpt-5-mini'));

        $body = $http->sent[0]['body'];
        $this->assertSame(4096, $body['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $body);
        $this->assertSame('low', $body['reasoning_effort']);
        $this->assertSame('Bearer sk-x', $http->sent[0]['headers']['Authorization']);
    }

    public function test_openrouter_lists_prices_and_reports_exact_cost(): void
    {
        $http = (new FakeHttp())
            ->answer(200, ['data' => [['id' => 'anthropic/claude-sonnet-5.5', 'name' => 'Claude Sonnet 5.5', 'pricing' => ['prompt' => '0.000002', 'completion' => '0.00001']]]])
            ->answer(200, ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $this->reply()]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'cost' => 0.0123]]);

        $provider = new OpenAiProvider($http, 'openrouter', 'https://openrouter.ai/api/v1', 'k', false);
        $models = $provider->models();
        $this->assertSame(['in' => 2.0, 'out' => 10.0], $models[0]['price']);

        $result = $provider->translate($this->job('anthropic/claude-sonnet-5.5'));
        $this->assertTrue($http->sent[1]['body']['usage']['include']);
        $this->assertSame(0.0123, Pricing::cost(['in' => 99, 'out' => 99], $result['usage']));
    }

    public function test_openai_model_list_drops_models_that_cannot_chat(): void
    {
        $http = (new FakeHttp())->answer(200, ['data' => [['id' => 'gpt-5'], ['id' => 'text-embedding-3-large'], ['id' => 'whisper-1'], ['id' => 'gpt-image-1']]]);
        $ids = array_column((new OpenAiProvider($http, 'openai', 'https://api.openai.com/v1', 'k', false))->models(), 'id');
        $this->assertSame(['gpt-5'], $ids);
    }

    public function test_gemini_request_reply_and_model_name_check(): void
    {
        $http = (new FakeHttp())->answer(200, [
            'modelVersion' => 'gemini-x',
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'thinking…', 'thought' => true], ['text' => $this->reply()]]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 7],
        ]);

        $result = (new GeminiProvider($http, 'AIzaKey'))->translate($this->job('gemini-2.5-flash'));
        $this->assertStringEndsWith('/v1beta/models/gemini-2.5-flash:generateContent', $http->sent[0]['target']['url']);
        $this->assertSame('AIzaKey', $http->sent[0]['headers']['x-goog-api-key']);
        $this->assertStringNotContainsString('AIzaKey', $http->sent[0]['target']['url'], 'the key never goes in the URL');
        $this->assertSame('application/json', $http->sent[0]['body']['generationConfig']['responseMimeType']);
        $this->assertSame(12, $result['usage']->output, 'thinking is billed as output');
        $this->assertSame('Antworten', $result['translations']['8']);

        $this->expectException(ProviderError::class);
        (new GeminiProvider(new FakeHttp(), 'k'))->translate($this->job('../../v1/files?x='));
    }

    public function test_deepl_hides_icu_from_the_engine_and_uses_a_run_glossary(): void
    {
        $http = (new FakeHttp())
            ->answer(200, ['glossary_id' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0'])
            ->answer(200, ['translations' => [['text' => '<x i="0"/>'], ['text' => '<x i="0"/> Antwort'], ['text' => '<x i="0"/> Antworten'], ['text' => 'Antworten']]])
            ->answer(204, []);

        $provider = new DeepLProvider($http, 'deepl-key:fx');
        $job = $this->job('quality_optimized', [['discussion', 'Diskussion']], 'informal');
        $result = $provider->translate($job);

        $this->assertSame('https://api-free.deepl.com:443/v2/glossaries', $http->sent[0]['target']['url']);
        $this->assertSame("discussion\tDiskussion", $http->sent[0]['body']['entries']);
        $translate = $http->sent[1]['body'];
        $this->assertSame(['<x i="0"/>', '<x i="0"/> reply', '<x i="0"/> replies', 'Reply'], $translate['text']);
        $this->assertSame('DE', $translate['target_lang']);
        $this->assertSame('xml', $translate['tag_handling']);
        $this->assertSame(['x'], $translate['ignore_tags']);
        $this->assertSame('prefer_less', $translate['formality']);
        $this->assertSame('0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', $translate['glossary_id']);
        $this->assertSame('DeepL-Auth-Key deepl-key:fx', $http->sent[1]['headers']['Authorization']);

        $this->assertSame('{count, plural, one {# Antwort} other {# Antworten}}', $result['translations']['7']);
        $this->assertSame('Antworten', $result['translations']['8']);
        $this->assertGreaterThan(0, $result['usage']->characters);

        // The run's glossary is deleted when the run ends.
        $provider->finish($job);
        $this->assertSame('DELETE', $http->sent[2]['method']);
        $this->assertStringEndsWith('/v2/glossaries/0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', $http->sent[2]['target']['url']);
    }

    public function test_google_sends_the_key_in_a_header(): void
    {
        $http = (new FakeHttp())->answer(200, ['data' => ['translations' => [
            ['translatedText' => '<x i="0"></x>'], ['translatedText' => '<x i="0"></x> Antwort'], ['translatedText' => '<x i="0"></x> Antworten'], ['translatedText' => 'Antworten'],
        ]]]);

        $result = (new GoogleProvider($http, 'AIzaG'))->translate($this->job('nmt'));
        $this->assertSame('AIzaG', $http->sent[0]['headers']['X-Goog-Api-Key']);
        $this->assertStringNotContainsString('AIzaG', $http->sent[0]['target']['url']);
        $this->assertSame('html', $http->sent[0]['body']['format']);
        $this->assertSame('{count, plural, one {# Antwort} other {# Antworten}}', $result['translations']['7']);
    }

    public function test_prompt_parse_is_lenient_but_only_returns_asked_ids(): void
    {
        $job = $this->job('m');
        $this->assertSame(['8' => 'B'], Prompt::parse('{"8":"B","x":"C"}', $job));
        $this->assertSame(['7' => 'A'], Prompt::parse("Here:\n```json\n{\"translations\":[{\"id\":7,\"text\":\"A\"}]}\n```", $job));

        $this->expectException(ProviderError::class);
        Prompt::parse('no json here', $job);
    }

    public function test_prompt_carries_tone_notes_glossary_and_feedback(): void
    {
        $job = $this->job('m', [['tag', 'label']], 'formal');
        $system = Prompt::system($job);
        $this->assertStringContainsString('formally', $system);
        $this->assertStringContainsString('Use du.', $system);
        $this->assertStringContainsString('tag => label', $system);
        $this->assertStringContainsString('"feedback":"Your reply had no translation', Prompt::user($job));
    }

    public function test_costs(): void
    {
        $usage = new Usage(input: 1_000_000, output: 1_000_000, cacheWrite: 1_000_000, cacheRead: 1_000_000);
        $this->assertEqualsWithDelta(4 + 20 + 5 + 0.4, Pricing::cost(['in' => 4.0, 'out' => 20.0], $usage), 1e-9);
        $this->assertEqualsWithDelta(25.0, Pricing::cost(['char' => 25.0], new Usage(characters: 1_000_000)), 1e-9);
    }

    public function test_locale_codes_per_provider(): void
    {
        $this->assertSame('PT-BR', Languages::deepl('pt_BR'));
        $this->assertSame('PT-PT', Languages::deepl('pt'));
        $this->assertSame('ZH-HANT', Languages::deepl('zh_TW'));
        $this->assertSame('ZH-HANS', Languages::deepl('zh-Hans'));
        $this->assertSame('ES-419', Languages::deepl('es_MX'));
        $this->assertSame('zh-TW', Languages::google('zh_Hant'));
        $this->assertSame('pt', Languages::google('pt_BR'));
        $this->assertSame('no', Languages::google('nb'));
        $this->assertTrue(Languages::valid('pt_BR'));
        $this->assertTrue(Languages::valid('zh_Hans_CN'));
        $this->assertFalse(Languages::valid('en'));
        $this->assertFalse(Languages::valid('../x'));
        $this->assertFalse(Languages::valid('de;drop'));
    }

    public function test_a_key_never_appears_in_a_stack_trace(): void
    {
        // storage/ that can't hold the encryption key: saving a key throws,
        // and that exception (and its trace) ends up in the forum's log.
        $storage = tempnam(sys_get_temp_dir(), 'pb');
        $paths = new \Flarum\Foundation\Paths(['base' => sys_get_temp_dir(), 'public' => sys_get_temp_dir(), 'storage' => $storage]);
        $vault = new \Ernestdefoe\Phrasebook\Settings\Vault($this->createStub(\Illuminate\Database\ConnectionInterface::class), $paths);

        try {
            $vault->put('openai', 'sk-TOPSECRET-1234567890');
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('TOPSECRET', $e->getTraceAsString());
        } finally {
            @unlink($storage);
        }
    }

    public function test_a_deepl_batch_that_fails_half_way_is_charged_for_the_half_that_was_sent(): void
    {
        $texts = [];
        $items = [];
        for ($i = 0; $i < 60; $i++) {
            $items[(string) $i] = ['key' => "core.k$i", 'text' => "String number $i", 'feedback' => null];
        }

        $http = (new FakeHttp())
            ->answer(200, ['translations' => array_map(fn ($i) => ['text' => "Zeichenkette $i"], range(0, 49))])
            ->answer(429, ['message' => 'Too many requests'], ['retry-after' => '5']);

        try {
            (new DeepLProvider($http, 'k'))->translate(new Job($items, 'de', 'German', 'quality_optimized', 'neutral', '', [], 0));
            $this->fail('no exception');
        } catch (BatchFailed $e) {
            $this->assertSame('rate_limit', $e->error->kind);
            $this->assertSame(array_sum(array_map(fn ($i) => mb_strlen("String number $i"), range(0, 49))), $e->usage->characters);
        }
    }
}
