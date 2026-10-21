<?php

namespace Ernestdefoe\Phrasebook\Run;

use Carbon\Carbon;
use Ernestdefoe\Phrasebook\Icu\Validator;
use Ernestdefoe\Phrasebook\Provider\BatchFailed;
use Ernestdefoe\Phrasebook\Provider\Catalog;
use Ernestdefoe\Phrasebook\Provider\Factory;
use Ernestdefoe\Phrasebook\Provider\Job;
use Ernestdefoe\Phrasebook\Provider\Pricing;
use Ernestdefoe\Phrasebook\Provider\ProviderError;
use Ernestdefoe\Phrasebook\Provider\Usage;
use Ernestdefoe\Phrasebook\Settings\Settings;
use Ernestdefoe\Phrasebook\Strings\Languages;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Advances a run by one batch. Called by the queue job, or by the admin page
 * itself on forums without a queue worker; a lease on the run row makes sure
 * only one of them works on a run at a time.
 *
 * Each batch: the pending strings (fewest attempts first), shrunk until its
 * worst case fits what is left of the budget; one call; every answer
 * checked by the Validator. A rejected answer goes back with the reason
 * attached, up to three tries (one for DeepL/Google, which would only say
 * the same again), then waits for the admin.
 */
class Processor
{
    public const MAX_ATTEMPTS = 3;

    /** Retryable failures in a row before the run pauses for the admin. */
    public const MAX_FAILURES = 8;

    /** Longer than the slowest call (local models: 600 s), so a lease outlives its batch. */
    private const LEASE_SECONDS = 660;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Factory $factory,
        private readonly Pricing $pricing,
        private readonly Settings $settings,
        private readonly LoggerInterface $log
    ) {
    }

    /**
     * @return bool whether the run still has work to do
     */
    public function step(Run $run): bool
    {
        if (! $this->lease($run)) {
            return $run->active();
        }

        // A batch can take minutes on a local model. Neither PHP's time limit
        // nor the admin closing the page may cut it off after the provider
        // has done (and billed) the work.
        @set_time_limit(self::LEASE_SECONDS);
        ignore_user_abort(true);

        try {
            $this->work($run);
        } finally {
            $run->locked_until = null;
            $run->save();
        }

        return $run->active();
    }

    /**
     * Takes the run for this process, unless another holds an unexpired lease.
     */
    private function lease(Run $run): bool
    {
        $now = Carbon::now();

        $taken = $this->db->table('phrasebook_runs')
            ->where('id', $run->id)
            ->whereIn('status', Run::ACTIVE)
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', $now))
            ->update(['locked_until' => $now->copy()->addSeconds(self::LEASE_SECONDS)]);

        $run->refresh();

        return $taken === 1;
    }

    private function work(Run $run): void
    {
        if ($run->status === 'waiting' && $run->next_attempt_at && $run->next_attempt_at->isFuture()) {
            return;
        }

        $def = Catalog::get($run->provider);
        $price = $this->pricing->for($run->provider, $run->model);

        if ($def === null || $price === null) {
            $this->pause($run, $def === null ? 'config' : 'no_price', '');

            return;
        }

        $mt = $def['kind'] === 'mt';
        $maxAttempts = $mt ? 1 : self::MAX_ATTEMPTS;
        $state = $run->state();
        $limit = (int) min($this->settings->batchSize(), $state['batch_limit'] ?? PHP_INT_MAX);

        $rows = $this->db->table('phrasebook_items')
            ->where('run_id', $run->id)
            ->where('status', 'pending')
            ->orderBy('attempts')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'run_id', 'key', 'namespace', 'reason', 'source', 'source_hash', 'current', 'errors', 'attempts']);

        if ($rows->isEmpty()) {
            $this->finish($run);

            return;
        }

        try {
            $provider = $this->factory->make($run->provider);
        } catch (ProviderError $e) {
            $this->pause($run, $e->kind, $e->detail);

            return;
        }

        // Shrink the batch until its worst case fits what is left.
        $left = (float) $run->budget - (float) $run->spent;
        $batch = $rows->all();

        while (true) {
            $job = $this->job($run, $batch, $state, 0);
            $job = $this->job($run, $batch, $state, Estimator::maxTokens($job));

            if (Estimator::worstCase($job, $price, $def['kind']) <= $left) {
                break;
            }

            if (count($batch) === 1) {
                $this->pause($run, 'budget', '');

                return;
            }

            $batch = array_slice($batch, 0, intdiv(count($batch), 2));
        }

        $run->status = 'running';
        $run->calls++;

        try {
            $result = $provider->translate($job);
            $this->charge($run, $price, $result['usage']);
            $this->apply($run, $batch, $result['translations'], $maxAttempts);
            $run->failures = 0;
            $state['bad_streak'] = 0;
            $run->error = null;
            $run->message = null;
        } catch (BatchFailed $e) {
            $this->charge($run, $price, $e->usage);
            $this->failed($run, $batch, $e->error, $state, $maxAttempts);
        } catch (ProviderError $e) {
            $this->failed($run, $batch, $e, $state, $maxAttempts);
        } finally {
            // A DeepL glossary made during the call is kept for the next batch.
            $state = array_merge($job->state, $state);
            $run->provider_state = json_encode($state) ?: null;
        }
    }

    /**
     * @param list<object> $batch
     * @param array<string, mixed> $state
     */
    private function job(Run $run, array $batch, array $state, int $maxTokens): Job
    {
        $items = [];

        foreach ($batch as $row) {
            $errors = json_decode((string) $row->errors, true);
            $items[(string) $row->id] = [
                'key' => (string) $row->key,
                'text' => (string) $row->source,
                'feedback' => is_array($errors) && $errors ? Validator::explain($errors) : null,
            ];
        }

        return new Job(
            $items,
            $run->locale,
            Languages::name($run->locale),
            $run->model,
            $run->tone,
            (string) $run->notes,
            Settings::glossary((string) $run->glossary),
            $maxTokens,
            $this->settings->effort(),
            $state
        );
    }

    /**
     * @param array{in?: float, out?: float, char?: float} $price
     */
    private function charge(Run $run, array $price, Usage $usage): void
    {
        $run->spent = (string) round((float) $run->spent + Pricing::cost($price, $usage), 6);
        $run->input_tokens += $usage->input + $usage->cacheWrite + $usage->cacheRead;
        $run->output_tokens += $usage->output;
        $run->characters += $usage->characters;
    }

    /**
     * @param list<object> $batch
     * @param array<string, string> $translations
     */
    private function apply(Run $run, array $batch, array $translations, int $maxAttempts): void
    {
        $now = Carbon::now();
        $rows = [];

        foreach ($batch as $row) {
            $text = $translations[(string) $row->id] ?? null;
            $errors = $text === null ? [['code' => 'no_answer', 'detail' => '']] : Validator::check((string) $row->source, $text);
            $attempts = (int) $row->attempts + 1;

            $rows[] = [
                'id' => $row->id,
                'run_id' => $row->run_id,
                'key' => $row->key,
                'namespace' => $row->namespace,
                'reason' => $row->reason,
                'source' => $row->source,
                'source_hash' => $row->source_hash,
                'current' => $row->current,
                'translation' => $text,
                'attempts' => $attempts,
                'errors' => $errors ? json_encode($errors) : null,
                'status' => $errors === [] ? 'translated' : ($attempts >= $maxAttempts ? 'failed' : 'pending'),
                'updated_at' => $now,
            ];
        }

        // One statement for the whole batch: every row exists, so this only
        // ever takes the update path.
        $this->db->table('phrasebook_items')->upsert($rows, ['id'], ['translation', 'attempts', 'errors', 'status', 'updated_at']);
    }

    /**
     * @param list<object> $batch
     * @param array<string, mixed> $state
     */
    private function failed(Run $run, array $batch, ProviderError $error, array &$state, int $maxAttempts): void
    {
        $this->log->info('[phrasebook] run '.$run->id.': '.$error->kind.' ('.$error->status.')');

        if ($error->fatal()) {
            $this->pause($run, $error->kind, $error->detail);

            return;
        }

        if ($error->retryable()) {
            $run->failures++;

            if ($run->failures > self::MAX_FAILURES) {
                $this->pause($run, $error->kind, $error->detail);

                return;
            }

            $wait = $error->retryAfter ?? min(300, 5 * 2 ** ($run->failures - 1));
            $run->status = 'waiting';
            $run->next_attempt_at = Carbon::now()->addSeconds(min(3600, $wait));
            $run->error = $error->kind;
            $run->message = $error->detail;

            return;
        }

        // The batch itself was the problem: halve it, and when one string on
        // its own still fails, set that string aside for the admin.
        if (count($batch) > 1) {
            $state['batch_limit'] = max(1, intdiv(count($batch), 2));

            return;
        }

        // String after string failing alone: it's the model, not the strings.
        $state['bad_streak'] = (int) ($state['bad_streak'] ?? 0) + 1;

        if ($state['bad_streak'] >= 3) {
            $this->pause($run, $error->kind, $error->detail);

            return;
        }

        $this->db->table('phrasebook_items')->where('id', $batch[0]->id)->update([
            'status' => 'failed',
            'attempts' => max($maxAttempts, (int) $batch[0]->attempts + 1),
            'errors' => json_encode([['code' => 'provider_'.$error->kind, 'detail' => $error->detail]]),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function pause(Run $run, string $error, string $message): void
    {
        $run->status = 'paused';
        $run->error = $error;
        $run->message = $message;
    }

    private function finish(Run $run): void
    {
        $run->status = 'review';
        $run->finished_at = Carbon::now();
        $run->error = null;
        $run->message = null;

        $state = $run->state();

        if ($state) {
            try {
                $provider = $this->factory->make($run->provider);
                $provider->finish(new Job([], $run->locale, '', $run->model, $run->tone, '', [], 0, 'low', $state));
            } catch (ProviderError) {
                // Nothing to tidy without a key.
            }
        }
    }
}
