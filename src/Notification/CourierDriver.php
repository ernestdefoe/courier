<?php

namespace Ernestdefoe\Courier\Notification;

use Ernestdefoe\Courier\Job\RelayNotificationJob;
use Ernestdefoe\Courier\Relay\RelayClient;
use Ernestdefoe\Courier\Reply\ReplyGrant;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\Driver\EmailNotificationDriver;
use Flarum\Notification\Driver\NotificationDriverInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;

/**
 * Sends reply-able notifications through the relay, and everything else the
 * way Flarum already did.
 *
 * 🚨 Takes over the 'email' driver name but wraps the built-in driver and
 * hands it everything it does not handle itself.
 *
 * Only a notification about a comment in a discussion can be replied to — there
 * is nowhere for a reply to a "you were made an admin" to go. Anything else,
 * and anything at all while the relay is not connected, falls through to the
 * original driver untouched. A forum that installs this must not lose
 * notifications because our service is having a bad afternoon.
 */
class CourierDriver implements NotificationDriverInterface
{
    public function __construct(
        private RelayClient $relay,
        private EmailNotificationDriver $fallback,
        private Container $container
    ) {
    }

    public function send(BlueprintInterface $blueprint, array $users): void
    {
        $post = $blueprint->getSubject();

        if (! $this->relay->configured() || ! $blueprint instanceof MailableInterface
            || ! $post instanceof CommentPost || ! $post->discussion_id) {
            $this->fallback->send($blueprint, $users);

            return;
        }

        $recipients = $this->recipients($blueprint, $users);

        /*
         * 🚨 Recorded before anything is sent: the record is what lets a reply
         * from this member to this discussion be posted later (see ReplyGrant).
         */
        ReplyGrant::grant(array_map(fn (User $u) => (int) $u->id, $recipients), (int) $post->discussion_id);

        /*
         * One queued job per member, as core's email driver does: the relay
         * call is outbound HTTP and must not run inside the request that
         * caused the notification. The queue is resolved here, at push time,
         * for the reason core's EmailNotificationDriver gives: this driver is
         * built at boot, before the routing queue wrapper exists.
         */
        /** @var Queue $queue */
        $queue = $this->container->make(Queue::class);

        foreach ($recipients as $user) {
            $queue->push(new RelayNotificationJob($blueprint, $user, $post));
        }
    }

    public function registerType(string $blueprintClass, array $driversEnabledByDefault): void
    {
        $this->fallback->registerType($blueprintClass, $driversEnabledByDefault);
    }

    /**
     * As the forum's own email driver does: a member who turned these emails
     * off gets none, from either of us.
     *
     * @param array<mixed> $users
     * @return list<User>
     */
    private function recipients(BlueprintInterface $blueprint, array $users): array
    {
        return array_values(array_filter($users, fn ($user) => $user instanceof User
            && $user->email
            && $user->is_email_confirmed
            && $user->shouldEmail($blueprint::getType())));
    }
}
