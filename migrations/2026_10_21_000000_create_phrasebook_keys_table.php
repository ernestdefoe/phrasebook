<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('phrasebook_keys', function (Blueprint $table) {
    $table->string('provider', 32)->primary();
    $table->text('secret');
    $table->string('hint', 8);
    $table->dateTime('updated_at')->nullable();
});
