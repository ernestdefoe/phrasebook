<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('phrasebook_runs', function (Blueprint $table) {
    $table->increments('id');
    $table->string('locale', 10);
    $table->string('provider', 32);
    $table->string('model', 191);
    $table->string('status', 16)->index();
    $table->decimal('budget', 10, 4);
    $table->decimal('spent', 14, 6)->default(0);
    $table->unsignedBigInteger('input_tokens')->default(0);
    $table->unsignedBigInteger('output_tokens')->default(0);
    $table->unsignedBigInteger('characters')->default(0);
    $table->unsignedInteger('calls')->default(0);
    $table->unsignedSmallInteger('failures')->default(0);
    $table->dateTime('next_attempt_at')->nullable();
    $table->dateTime('locked_until')->nullable();
    $table->text('provider_state')->nullable();
    $table->string('error', 32)->nullable();
    $table->text('message')->nullable();
    $table->string('tone', 16);
    $table->text('notes')->nullable();
    $table->text('glossary')->nullable();
    $table->unsignedInteger('user_id')->nullable();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();
    $table->dateTime('finished_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
