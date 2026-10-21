<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('phrasebook_items', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('run_id');
    $table->string('key', 255);
    $table->string('namespace', 100);
    $table->string('reason', 16);
    $table->text('source');
    $table->char('source_hash', 40);
    $table->text('current')->nullable();
    $table->text('translation')->nullable();
    $table->string('status', 16);
    $table->unsignedTinyInteger('attempts')->default(0);
    $table->text('errors')->nullable();
    $table->dateTime('updated_at')->nullable();

    $table->unique(['run_id', 'key']);
    $table->index(['run_id', 'status']);
    $table->foreign('run_id')->references('id')->on('phrasebook_runs')->cascadeOnDelete();
});
