<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Which English text each saved translation was made from, so a later change
// to the English shows the translation as out of date.
return Migration::createTable('phrasebook_ledger', function (Blueprint $table) {
    $table->string('locale', 10);
    $table->string('key', 255);
    $table->char('source_hash', 40);
    $table->dateTime('updated_at')->nullable();

    $table->primary(['locale', 'key']);
});
