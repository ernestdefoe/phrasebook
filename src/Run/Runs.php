<?php

namespace Ernestdefoe\Phrasebook\Run;

use Carbon\Carbon;
use Ernestdefoe\Phrasebook\Icu\Validator;
use Ernestdefoe\Phrasebook\Job\ProcessRun;
use Ernestdefoe\Phrasebook\Provider\Factory;
use Ernestdefoe\Phrasebook\Provider\Job;
use Ernestdefoe\Phrasebook\Provider\ProviderError;
use Ernestdefoe\Phrasebook\Strings\Scanner;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Queue\SyncQueue;

/**
 * Starting, steering and saving runs.
 */
class Runs
{
    public const MAX_STRINGS = 20000;

    /** Linguist's key column is 180 characters. */
    public const LINGUIST_KEY_LENGTH = 180;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Queue $queue,
        private readonly ExtensionManager $extensions,
        private readonly Scanner $scanner,
        private readonly Container $container,
        private readonly Factory $factory
    ) {
    }

    /** True when jobs run on a worker; false when the admin page drives runs. */
    public function queued(): bool
    {
        $queue = method_exists($this->queue, 'getDriver') ? $this->queue->getDriver() : $this->queue;

        return ! $queue instanceof SyncQueue;
    }

    /**
     * @param array<string, array{source: string, current: string|null, reason: string}> $strings
     * @param array{provider: string, model: string, budget: float, tone: string, notes: string, glossary: string} $options
     */
    public function start(string $locale, array $strings, array $options, ?int $userId): Run
    {
        if ($strings === []) {
            throw new ValidationException(['strings' => 'nothing_to_translate']);
        }

        if (count($strings) > self::MAX_STRINGS) {
            throw new ValidationException(['strings' => 'too_many']);
        }

        $run = new Run();
        $run->locale = $locale;
        $run->provider = $options['provider'];
        $run->model = $options['model'];
        $run->status = 'queued';
        $run->budget = (string) $options['budget'];
        $run->spent = '0';
        $run->tone = $options['tone'];
        $run->notes = $options['notes'];
        $run->glossary = $options['glossary'];
        $run->user_id = $userId;

        $this->db->transaction(function () use ($run, $strings) {
            $run->save();
            $now = Carbon::now();
            $rows = [];

            foreach ($strings as $key => $row) {
                $rows[] = [
                    'run_id' => $run->id,
                    'key' => $key,
                    'namespace' => Scanner::namespace($key),
                    'reason' => $row['reason'],
                    'source' => $row['source'],
                    'source_hash' => sha1($row['source']),
                    'current' => $row['current'],
                    'status' => 'pending',
                    'updated_at' => $now,
                ];

                if (count($rows) === 500) {
                    $this->db->table('phrasebook_items')->insert($rows);
                    $rows = [];
                }
            }

            if ($rows) {
                $this->db->table('phrasebook_items')->insert($rows);
            }
        });

        $this->dispatch($run);

        return $run;
    }

    public function dispatch(Run $run): void
    {
        if ($this->queued()) {
            $this->queue->push(new ProcessRun($run->id));
        }
    }

    /** Back to work after a pause, a cancel, or retries the admin asked for. */
    public function resume(Run $run, ?float $budget = null): void
    {
        if ($budget !== null) {
            $run->budget = (string) $budget;
        }

        if ($run->active()) {
            $run->save();

            return;
        }

        $run->status = 'queued';
        $run->failures = 0;
        $run->error = null;
        $run->message = null;
        $run->next_attempt_at = null;
        $run->finished_at = null;
        $run->save();

        $this->dispatch($run);
    }

    public function cancel(Run $run): void
    {
        if (! $run->active() && $run->status !== 'paused') {
            return;
        }

        $this->db->table('phrasebook_runs')->where('id', $run->id)->update(['status' => 'cancelled', 'next_attempt_at' => null]);
        $run->refresh();
        $this->tidy($run);
    }

    public function delete(Run $run): void
    {
        $this->tidy($run);

        // Under the same row lock a batch takes to write its answers.
        $this->db->transaction(function () use ($run) {
            $this->db->table('phrasebook_runs')->where('id', $run->id)->lockForUpdate()->first();
            $this->db->table('phrasebook_items')->where('run_id', $run->id)->delete();
            $this->db->table('phrasebook_runs')->where('id', $run->id)->delete();
        });
    }

    /**
     * Edit, accept, reject or retry one string. A translation the Validator
     * rejects is never stored, so a broken string can't reach the forum.
     *
     * @return object the updated row
     */
    public function update(Run $run, int $itemId, ?string $translation, string $status): object
    {
        $item = $this->db->table('phrasebook_items')->where('run_id', $run->id)->where('id', $itemId)->first();

        if (! $item) {
            throw new \Flarum\Http\Exception\RouteNotFoundException();
        }

        if (! in_array($status, ['accepted', 'rejected', 'translated', 'pending'], true)) {
            throw new ValidationException(['status' => 'invalid']);
        }

        $changes = ['updated_at' => Carbon::now()];

        if ($translation !== null) {
            $translation = str_replace("\r\n", "\n", $translation);
            $errors = Validator::check((string) $item->source, $translation);

            if ($errors) {
                throw new ValidationException(['translation' => json_encode($errors) ?: 'invalid']);
            }

            $changes['translation'] = $translation;
            $changes['errors'] = null;
        }

        $text = $changes['translation'] ?? $item->translation;

        if ($status === 'accepted' && ($text === null || Validator::check((string) $item->source, (string) $text))) {
            throw new ValidationException(['translation' => 'invalid']);
        }

        if ($status === 'pending') {
            $changes['attempts'] = 0;
            $changes['errors'] = null;
        }

        $changes['status'] = $status;

        $this->db->table('phrasebook_items')->where('id', $item->id)->update($changes);

        if ($status === 'pending') {
            $this->resume($run);
        }

        return $this->db->table('phrasebook_items')->where('id', $item->id)->first();
    }

    /** Accepts every string that came back valid. */
    public function acceptAll(Run $run): int
    {
        return $this->db->table('phrasebook_items')
            ->where('run_id', $run->id)
            ->where('status', 'translated')
            ->update(['status' => 'accepted', 'updated_at' => Carbon::now()]);
    }

    /**
     * Writes the accepted strings into Linguist, where they override the
     * language pack, and remembers which English each was made from.
     *
     * @return array{saved: int, skipped: int, installed: bool}
     */
    public function saveToLinguist(Run $run): array
    {
        if (! $this->extensions->isEnabled('fof-linguist')) {
            throw new ValidationException(['target' => 'linguist_disabled']);
        }

        $saved = 0;
        $skipped = 0;
        $now = Carbon::now();
        // Checked against the English as it is now: an extension updated since
        // the run may have renamed a placeholder.
        $english = $this->scanner->english();

        $this->db->table('phrasebook_items')
            ->where('run_id', $run->id)
            ->where('status', 'accepted')
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($run, &$saved, &$skipped, $now, $english) {
                $strings = [];
                $ledger = [];
                $ids = [];

                foreach ($items as $item) {
                    $source = $english[$item->key] ?? null;

                    if ($source === null || strlen($item->key) > self::LINGUIST_KEY_LENGTH || Validator::check($source, (string) $item->translation)) {
                        $skipped++;
                        continue;
                    }

                    $strings[] = ['key' => $item->key, 'locale' => $run->locale, 'value' => $item->translation, 'created_at' => $now, 'updated_at' => $now];
                    $ledger[] = ['locale' => $run->locale, 'key' => $item->key, 'source_hash' => $item->source_hash, 'updated_at' => $now];
                    $ids[] = $item->id;
                }

                if (! $ids) {
                    return;
                }

                // One statement per table per chunk, on Linguist's own unique index.
                $this->db->transaction(function () use ($strings, $ledger, $ids, $now) {
                    $this->db->table('fof_linguist_strings')->upsert($strings, ['key', 'locale'], ['value', 'updated_at']);
                    $this->db->table('phrasebook_ledger')->upsert($ledger, ['locale', 'key'], ['source_hash', 'updated_at']);
                    $this->db->table('phrasebook_items')->whereIn('id', $ids)->update(['status' => 'saved', 'updated_at' => $now]);
                });

                $saved += count($ids);
            });

        if ($saved > 0 && class_exists(\FoF\Linguist\Repositories\CacheStatusRepository::class)) {
            $this->container->make(\FoF\Linguist\Repositories\CacheStatusRepository::class)->translationWasModified();
        }

        // Linguist rows for a language no pack provides are stored and never
        // shown: say so, rather than let it look like it worked.
        return ['saved' => $saved, 'skipped' => $skipped, 'installed' => $this->container->make(\Flarum\Locale\LocaleManager::class)->hasLocale($run->locale)];
    }

    /**
     * Records which English each exported string was made from, so a later
     * change to the English shows it as out of date.
     *
     * @param array<string, string> $hashes key => sha1 of the English
     */
    public function remember(string $locale, array $hashes): void
    {
        $now = Carbon::now();

        foreach (array_chunk($hashes, 500, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $key => $hash) {
                $rows[] = ['locale' => $locale, 'key' => (string) $key, 'source_hash' => $hash, 'updated_at' => $now];
            }
            $this->db->table('phrasebook_ledger')->upsert($rows, ['locale', 'key'], ['source_hash', 'updated_at']);
        }
    }

    /** Lets the provider remove anything it made for the run (a DeepL glossary). */
    private function tidy(Run $run): void
    {
        $state = $run->state();

        if (! $state) {
            return;
        }

        try {
            $this->factory->make($run->provider)->finish(new Job([], $run->locale, '', $run->model, $run->tone, '', [], 0, 'low', $state));
        } catch (ProviderError) {
        }

        $run->provider_state = null;
        $run->save();
    }
}
