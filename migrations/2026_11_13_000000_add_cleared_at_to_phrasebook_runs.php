<?php

use Flarum\Database\Migration;

return Migration::addColumns('phrasebook_runs', [
    'cleared_at' => ['dateTime', 'nullable' => true],
]);
