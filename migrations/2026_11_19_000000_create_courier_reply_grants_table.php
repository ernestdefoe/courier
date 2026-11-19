<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One row per member and discussion the forum has emailed through the relay.
 * A reply the relay hands back is posted only when a recent row matches it,
 * so the relay cannot name an author the forum never asked it to email.
 */
return Migration::createTable('courier_reply_grants', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('discussion_id');
    $table->dateTime('granted_at');

    $table->unique(['user_id', 'discussion_id']);
    $table->index('granted_at');

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
});
