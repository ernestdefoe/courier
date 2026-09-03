<?php

namespace Ernestdefoe\Courier;

use Ernestdefoe\Courier\Console\CollectRepliesCommand;
use Ernestdefoe\Courier\Notification\CourierDriver;
use Flarum\Extend;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    /*
     * 🚨 Registered under the name 'email', which REPLACES the built-in driver
     * rather than adding a second one. Adding one would mean every reply-able
     * notification going out twice — once from the forum and once from us.
     * CourierDriver takes the original as a dependency and falls back to it,
     * so nothing is lost when the relay is unreachable or the notification is
     * not something anyone could reply to.
     */
    (new Extend\Notification())
        ->driver('email', CourierDriver::class),

    (new Extend\Console())
        ->command(CollectRepliesCommand::class)
        /*
         * Every five minutes. Email is not a real-time medium — a few minutes
         * is invisible beside what mail already spends in transit — and a
         * tighter loop would poll an empty queue all day on a quiet forum.
         */
        ->schedule(CollectRepliesCommand::class, fn ($event) => $event->everyFiveMinutes()),

    (new Extend\Settings())
        ->default('courier.relay_url', 'https://ernestdefoe.online')
        ->default('courier.site_key', ''),
];
