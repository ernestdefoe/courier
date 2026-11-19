<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * The relay's id for every reply this forum has posted, so a reply the relay
 * offers again (after an acknowledgement that never arrived) is not posted twice.
 */
return Migration::createTable('courier_collected_replies', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedBigInteger('relay_message_id')->unique();
    $table->dateTime('collected_at')->index();
});
