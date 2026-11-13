<?php

use Illuminate\Database\Schema\Builder;

// Runs saved before runs were cleared from the list: every string already
// saved or rejected, nothing left for the admin.
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $open = $db->table('phrasebook_items')->whereIn('status', ['pending', 'translated', 'failed', 'accepted'])->select('run_id');

        $db->table('phrasebook_runs')
            ->whereNull('cleared_at')
            ->whereIn('status', ['review', 'cancelled'])
            ->whereNotIn('id', $open)
            ->update(['cleared_at' => \Carbon\Carbon::now()]);
    },
    'down' => function (Builder $schema) {
    },
];
