<?php

namespace Ernestdefoe\Phrasebook\Api;

use Ernestdefoe\Phrasebook\Run\Processor;
use Ernestdefoe\Phrasebook\Run\Run;
use Ernestdefoe\Phrasebook\Run\Runs;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A run's status and counts (GET), its actions (POST .../{action}) and
 * deleting it (DELETE).
 *
 * "step" processes one batch here, in this request: how the admin page
 * carries a run on forums without a queue worker.
 */
class RunController extends Controller
{
    public function __construct(
        private readonly Runs $runs,
        private readonly Processor $processor,
        private readonly ConnectionInterface $db
    ) {
    }

    protected function respond(ServerRequestInterface $request): mixed
    {
        $run = $this->run($request);
        $action = $this->query($request, 'action');
        $extra = [];

        if ($request->getMethod() === 'DELETE') {
            $this->runs->delete($run);

            return ['deleted' => true];
        }

        if ($request->getMethod() === 'POST') {
            match ($action) {
                'step' => $this->processor->step($run),
                'resume' => $this->runs->resume($run, $this->budget($request, $run)),
                'cancel' => $this->runs->cancel($run),
                'accept-all' => $extra['accepted'] = $this->runs->acceptAll($run),
                'save' => $extra = $this->runs->saveToLinguist($run),
                default => throw new \Flarum\Http\Exception\RouteNotFoundException(),
            };
            $fresh = Run::query()->find($run->id);

            // Deleted by another admin while this request worked.
            if (! $fresh instanceof Run) {
                throw new \Flarum\Http\Exception\RouteNotFoundException();
            }

            $run = $fresh;
        }

        return ['run' => self::present($run), 'counts' => $this->counts($run)] + $extra;
    }

    /** A new budget sent with "resume", for a run paused at its cap. */
    private function budget(ServerRequestInterface $request, Run $run): ?float
    {
        $budget = $this->input($request, 'budget');

        if ($budget === null || $budget === '') {
            return null;
        }

        if (! is_numeric($budget) || (float) $budget < (float) $run->spent || (float) $budget > 10000) {
            throw new \Flarum\Foundation\ValidationException(['budget' => 'invalid']);
        }

        return round((float) $budget, 4);
    }

    /**
     * @return array<string, int>
     */
    private function counts(Run $run): array
    {
        $counts = ['pending' => 0, 'translated' => 0, 'failed' => 0, 'accepted' => 0, 'rejected' => 0, 'saved' => 0];

        $rows = $this->db->table('phrasebook_items')
            ->where('run_id', $run->id)
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }
}
