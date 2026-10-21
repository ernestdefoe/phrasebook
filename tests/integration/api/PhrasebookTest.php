<?php

namespace Ernestdefoe\Phrasebook\Tests\integration\api;

use Ernestdefoe\Phrasebook\Net\Http;
use Ernestdefoe\Phrasebook\Settings\Settings;
use Ernestdefoe\Phrasebook\Tests\unit\FakeHttp;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class PhrasebookTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-linguist', 'ernestdefoe-phrasebook');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
        ]);

        $this->setting(Settings::PREFIX.'provider', 'openai');
        $this->setting(Settings::PREFIX.'models', '{"openai":"gpt-test"}');
        $this->setting(Settings::PREFIX.'prices', '{"openai|gpt-test":{"in":1,"out":2}}');
        $this->setting(Settings::PREFIX.'batch_size', '100');

        $this->http = new FakeHttp();
    }

    private function call(string $method, string $path, ?int $actor = 1, array $body = [], array $query = []): ResponseInterface
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($body) {
            $options['json'] = $body;
        }

        $request = $this->request($method, '/api/phrasebook'.$path, $options);

        if ($query) {
            $request = $request->withQueryParams($query);
        }

        $this->app()->getContainer()->instance(Http::class, $this->http);

        return $this->send($request);
    }

    /**
     * @return array<mixed>
     */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /** A model that translates every string as "XX: " + the English. */
    private function answerBatches(): void
    {
        $this->http = new class extends FakeHttp {
            protected function send(string $method, array $target, array $headers, ?string $body, int $timeout): \Ernestdefoe\Phrasebook\Net\Response
            {
                $this->sent[] = ['method' => $method, 'target' => $target, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true)];
                $items = json_decode(json_decode((string) $body, true)['messages'][1]['content'], true);
                $out = array_map(fn ($item) => ['id' => $item['id'], 'text' => 'XX: '.$item['text']], $items);

                return new \Ernestdefoe\Phrasebook\Net\Response(200, [], (string) json_encode([
                    'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['translations' => $out])]]],
                    'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500],
                ]));
            }
        };
    }

    /**
     * Steps a run until it stops working.
     *
     * @return array<mixed>
     */
    private function finish(int $id): array
    {
        for ($i = 0; $i < 100; $i++) {
            $run = $this->json($this->call('POST', "/runs/$id/step"));
            if (! in_array($run['run']['status'], ['queued', 'running'], true)) {
                return $run;
            }
        }

        return $run;
    }

    /** @return list<array{0: string, 1: string, 2?: array<string, mixed>}> */
    public static function routes(): array
    {
        return [
            ['GET', ''],
            ['POST', '/keys/openai', ['key' => 'sk-x']],
            ['DELETE', '/keys/openai'],
            ['GET', '/models/openai'],
            ['POST', '/test/openai', ['model' => 'gpt-test']],
            ['GET', '/scan'],
            ['POST', '/runs', ['locales' => ['de'], 'reasons' => ['missing'], 'provider' => 'openai', 'model' => 'gpt-test', 'budget' => 1]],
            ['GET', '/runs/1'],
            ['POST', '/runs/1/step'],
            ['POST', '/runs/1/save'],
            ['DELETE', '/runs/1'],
            ['GET', '/runs/1/items'],
            ['PATCH', '/runs/1/items/1', ['status' => 'accepted']],
            ['POST', '/export', ['locale' => 'de']],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('routes')]
    public function every_route_is_for_administrators_only(string $method, string $path, array $body = []): void
    {
        // A guest's write is stopped by the CSRF check (400) before it gets that far.
        $this->assertContains($this->call($method, $path, null, $body)->getStatusCode(), $method === 'GET' ? [401, 403] : [400, 401, 403], "guest $method $path");
        $this->assertSame(403, $this->call($method, $path, 2, $body)->getStatusCode(), "member $method $path");
        $this->assertSame([], $this->http->sent, 'nothing was sent anywhere');
    }

    #[Test]
    public function the_key_is_stored_encrypted_and_never_sent_back(): void
    {
        $saved = $this->call('POST', '/keys/openai', 1, ['key' => 'sk-proj-SECRETSECRETSECRET1234']);
        $this->assertSame(200, $saved->getStatusCode());
        $this->assertSame('…1234', $this->json($saved)['keyHint']);

        $row = $this->database()->table('phrasebook_keys')->where('provider', 'openai')->first();
        $this->assertStringNotContainsString('SECRET', $row->secret);

        $state = (string) $this->call('GET', '')->getBody();
        $this->assertStringNotContainsString('SECRET', $state);
        $this->assertStringContainsString('"hasKey":true', $state);

        // Not in the forum's settings either (they all go to the admin page).
        $this->assertStringNotContainsString('SECRET', (string) json_encode($this->database()->table('settings')->get()));

        // And it is what the provider receives.
        $this->answerBatches();
        $this->call('POST', '/test/openai', 1, ['model' => 'gpt-test', 'locale' => 'de']);
        $this->assertSame('Bearer sk-proj-SECRETSECRETSECRET1234', $this->http->sent[0]['headers']['Authorization']);
    }

    #[Test]
    public function a_run_translates_validates_and_saves_to_linguist(): void
    {
        $this->call('POST', '/keys/openai', 1, ['key' => 'sk-x']);
        $this->answerBatches();

        $estimate = $this->json($this->call('POST', '/runs', 1, ['dry' => true, 'locales' => ['nl'], 'namespaces' => ['core'], 'reasons' => ['missing'], 'provider' => 'openai', 'model' => 'gpt-test', 'budget' => 5]));
        $strings = $estimate['locales']['nl']['strings'];
        $this->assertGreaterThan(10, $strings);
        $this->assertGreaterThan(0, $estimate['locales']['nl']['high']);
        $this->assertSame([], $this->http->sent, 'a dry run calls nobody');

        $started = $this->call('POST', '/runs', 1, ['locales' => ['nl'], 'namespaces' => ['core'], 'reasons' => ['missing'], 'provider' => 'openai', 'model' => 'gpt-test', 'budget' => 5, 'tone' => 'formal', 'glossary' => 'tag = label']);
        $this->assertSame(200, $started->getStatusCode(), (string) $started->getBody());
        $id = $this->json($started)['runs'][0]['id'];

        $run = $this->finish($id);

        $this->assertSame('review', $run['run']['status']);
        $this->assertSame($strings, $run['counts']['translated']);
        $this->assertEqualsWithDelta(count($this->http->sent) * 0.002, $run['run']['spent'], 1e-6);
        $this->assertStringContainsString('formally', $this->http->sent[0]['body']['messages'][0]['content']);
        $this->assertStringContainsString('tag => label', $this->http->sent[0]['body']['messages'][0]['content']);

        $this->assertSame($strings, $this->json($this->call('POST', "/runs/$id/accept-all"))['accepted']);
        $saved = $this->json($this->call('POST', "/runs/$id/save"));
        $this->assertSame($strings, $saved['saved']);
        $this->assertSame($strings, $this->database()->table('fof_linguist_strings')->where('locale', 'nl')->count());

        // Saved strings no longer count as missing.
        $scan = $this->json($this->call('GET', '/scan', 1, [], ['locale' => 'nl']));
        $linguist = array_values(array_filter($scan['groups'], fn ($g) => $g['namespace'] === 'core'))[0];
        $this->assertSame(0, $linguist['missing']);

        // And the export carries them.
        $export = $this->call('POST', '/export', 1, ['locale' => 'nl', 'format' => 'yaml']);
        $this->assertSame(200, $export->getStatusCode());
        $this->assertStringContainsString('XX: ', (string) $export->getBody());
        $this->assertStringContainsString('attachment;', $export->getHeaderLine('Content-Disposition'));
    }

    #[Test]
    public function the_budget_is_never_exceeded(): void
    {
        $this->call('POST', '/keys/openai', 1, ['key' => 'sk-x']);
        $this->answerBatches();

        $budget = 0.02;
        $started = $this->json($this->call('POST', '/runs', 1, ['locales' => ['nl'], 'reasons' => ['missing'], 'provider' => 'openai', 'model' => 'gpt-test', 'budget' => $budget]));
        $id = $started['runs'][0]['id'];

        for ($i = 0; $i < 50; $i++) {
            $run = $this->json($this->call('POST', "/runs/$id/step"));
            if ($run['run']['status'] !== 'running' && $run['run']['status'] !== 'queued') {
                break;
            }
        }

        $this->assertSame('paused', $run['run']['status']);
        $this->assertSame('budget', $run['run']['error']);
        $this->assertLessThanOrEqual($budget, $run['run']['spent']);
        $this->assertGreaterThan(0, $run['counts']['pending']);
    }

    #[Test]
    public function a_broken_translation_can_never_be_accepted(): void
    {
        $this->call('POST', '/keys/openai', 1, ['key' => 'sk-x']);
        $this->answerBatches();

        $id = $this->json($this->call('POST', '/runs', 1, ['locales' => ['nl'], 'namespaces' => ['core'], 'reasons' => ['missing'], 'provider' => 'openai', 'model' => 'gpt-test', 'budget' => 5]))['runs'][0]['id'];
        $this->finish($id);

        $item = $this->database()->table('phrasebook_items')->where('run_id', $id)->where('source', 'like', '%{%')->first();
        $this->assertNotNull($item);

        foreach (['kapot', '<script>alert(1)</script>'.$item->source, '=> core.ref.delete', $item->source.' <img src=x onerror=alert(1)>'] as $bad) {
            $response = $this->call('PATCH', "/runs/$id/items/{$item->id}", 1, ['translation' => $bad, 'status' => 'accepted']);
            $this->assertSame(422, $response->getStatusCode(), $bad);
        }

        $this->assertSame('XX: '.$item->source, $this->database()->table('phrasebook_items')->where('id', $item->id)->value('translation'));

        // An item can only be reached through its own run.
        $other = $this->json($this->call('POST', '/runs', 1, ['locales' => ['fr'], 'namespaces' => ['core'], 'reasons' => ['missing'], 'provider' => 'openai', 'model' => 'gpt-test', 'budget' => 5]));
        $this->assertSame(404, $this->call('PATCH', '/runs/'.$other['runs'][0]['id']."/items/{$item->id}", 1, ['status' => 'accepted'])->getStatusCode());
    }

    #[Test]
    public function a_custom_endpoint_on_this_machine_needs_the_admins_say_so(): void
    {
        $this->setting(Settings::PREFIX.'base_urls', '{"custom":"http://127.0.0.1:11434/v1"}');
        $models = $this->json($this->call('GET', '/models/custom'));
        $this->assertSame('local_disabled', $models['error']['detail']);
        $this->assertSame([], $this->http->sent);

        $settings = $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->set(Settings::PREFIX.'base_urls', '{"custom":"http://169.254.169.254/latest"}');
        $settings->set(Settings::PREFIX.'allow_local', '1');

        $models = $this->json($this->call('GET', '/models/custom', 1, [], ['refresh' => '1']));
        $this->assertSame('blocked_address', $models['error']['detail']);
        $this->assertSame([], $this->http->sent);
    }

    #[Test]
    public function locales_and_inputs_are_validated(): void
    {
        foreach (['../../etc', 'en', 'de;x', str_repeat('a', 30)] as $locale) {
            $this->assertSame(422, $this->call('GET', '/scan', 1, [], ['locale' => $locale])->getStatusCode(), $locale);
        }

        $base = ['locales' => ['nl'], 'reasons' => ['missing'], 'provider' => 'openai', 'model' => 'gpt-test', 'budget' => 1];
        $this->assertSame(422, $this->call('POST', '/runs', 1, ['provider' => 'nope'] + $base)->getStatusCode());
        $this->assertSame(422, $this->call('POST', '/runs', 1, ['tone' => 'rude'] + $base)->getStatusCode());
        $this->assertSame(422, $this->call('POST', '/runs', 1, ['budget' => -1] + $base)->getStatusCode());
        $this->assertSame(422, $this->call('POST', '/runs', 1, $base)->getStatusCode(), 'no key saved');
        $this->assertSame(422, $this->call('POST', '/runs', 1, ['model' => 'unpriced'] + $base)->getStatusCode(), 'no price');
    }

    #[Test]
    public function on_a_queue_a_run_is_a_chain_of_one_batch_jobs_that_backs_off(): void
    {
        $this->call('POST', '/keys/openai', 1, ['key' => 'sk-x']);
        $this->answerBatches();
        $id = $this->json($this->call('POST', '/runs', 1, ['locales' => ['nl'], 'reasons' => ['missing'], 'provider' => 'openai', 'model' => 'gpt-test', 'budget' => 5]))['runs'][0]['id'];

        $queue = new class extends \Illuminate\Queue\NullQueue {
            /** @var list<array{0: int, 1: mixed}> */
            public array $pushed = [];

            public function push($job, $data = '', $queue = null)
            {
                $this->pushed[] = [0, $job];
            }

            public function later($delay, $job, $data = '', $queue = null)
            {
                $this->pushed[] = [$delay, $job];
            }
        };

        $container = $this->app()->getContainer();
        $container->instance(Http::class, $this->http);
        $job = new \Ernestdefoe\Phrasebook\Job\ProcessRun($id);
        $job->handle($container->make(\Ernestdefoe\Phrasebook\Run\Processor::class), $queue);

        // One batch done, the next job queued straight away.
        $this->assertCount(1, $this->http->sent);
        $this->assertSame([0], array_column($queue->pushed, 0));
        $this->assertSame($id, $queue->pushed[0][1]->runId);

        // A rate limit: the next job waits as long as the provider asked.
        $limited = new FakeHttp();
        $limited->answer(429, ['error' => ['message' => 'slow down']], ['retry-after' => '42']);
        $container->instance(Http::class, $limited);
        $container->forgetInstance(\Ernestdefoe\Phrasebook\Provider\Factory::class);
        $job->handle($container->make(\Ernestdefoe\Phrasebook\Run\Processor::class), $queue);

        $this->assertSame('waiting', $this->database()->table('phrasebook_runs')->where('id', $id)->value('status'));
        $this->assertGreaterThanOrEqual(40, $queue->pushed[1][0]);
        $this->assertLessThanOrEqual(42, $queue->pushed[1][0]);
    }
}
