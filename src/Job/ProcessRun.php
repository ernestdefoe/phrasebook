<?php

namespace Ernestdefoe\Phrasebook\Job;

use Ernestdefoe\Phrasebook\Run\Processor;
use Ernestdefoe\Phrasebook\Run\Run;
use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Queue\Queue;

/**
 * One batch of a run, then the next job for the next batch: a run is a chain
 * of short jobs, never one long one, so a worker restart loses one batch at
 * most and other jobs get their turn in between.
 */
class ProcessRun extends AbstractJob
{
    public function __construct(public readonly int $runId)
    {
    }

    public function handle(Processor $processor, Queue $queue): void
    {
        $run = Run::query()->find($this->runId);

        if (! $run instanceof Run || ! $processor->step($run)) {
            return;
        }

        $run = Run::query()->find($this->runId);

        // Another process holds the run (the admin page, or a second chain):
        // let that one carry it rather than racing it.
        if (! $run instanceof Run || $run->locked_until !== null) {
            return;
        }

        $delay = $run->status === 'waiting' && $run->next_attempt_at ? max(1, $run->next_attempt_at->getTimestamp() - time()) : 0;

        $delay > 0
            ? $queue->later($delay, new self($run->id))
            : $queue->push(new self($run->id));
    }
}
