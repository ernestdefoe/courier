<?php

namespace Ernestdefoe\Courier\Notification;

use Ernestdefoe\Courier\Relay\RelayClient;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\Driver\EmailNotificationDriver;
use Flarum\Notification\Driver\NotificationDriverInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Post\Post;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends reply-able notifications through the relay, and everything else the
 * way Flarum already did.
 *
 * 🚨 Decorates the built-in email driver rather than replacing it.
 *
 * Only a notification about a post in a discussion can be replied to — there is
 * nowhere for a reply to a "you were made an admin" to go. Anything else, and
 * anything at all when the relay is unreachable, falls through to the original
 * driver untouched. A forum that installs this must not lose notifications
 * because our service is having a bad afternoon.
 */
class CourierDriver implements NotificationDriverInterface
{
    public function __construct(
        private RelayClient $relay,
        private EmailNotificationDriver $fallback,
        private TranslatorInterface $translator
    ) {
    }

    public function send(BlueprintInterface $blueprint, array $users): void
    {
        if (! $this->relay->configured() || ! $blueprint instanceof MailableInterface) {
            $this->fallback->send($blueprint, $users);

            return;
        }

        $subject = $blueprint->getEmailSubject($this->translator);
        $post    = $this->postFrom($blueprint);

        if (! $post || ! $post->discussion_id) {
            $this->fallback->send($blueprint, $users);

            return;
        }

        $unsent = [];

        foreach ($users as $user) {
            if (! $user instanceof User || ! $user->email || ! $user->is_email_confirmed) {
                continue;
            }

            $ok = $this->relay->post('mail/notify', [
                'userId'       => (int) $user->id,
                'discussionId' => (int) $post->discussion_id,
                'postId'       => (int) $post->id,
                'subject'      => $subject,
                'body'         => $this->body($post, $subject),
                'to'           => ['address' => $user->email, 'name' => $user->display_name],
            ]);

            if (! ($ok['sent'] ?? false)) {
                $unsent[] = $user;
            }
        }

        /*
         * 🚨 Anyone the relay could not reach is sent the ordinary way. They
         * lose the ability to reply by email for that one notification, which
         * is a far smaller failure than never hearing about it.
         */
        if ($unsent) {
            $this->fallback->send($blueprint, $unsent);
        }
    }

    public function registerType(string $blueprintClass, array $driversEnabledByDefault): void
    {
        $this->fallback->registerType($blueprintClass, $driversEnabledByDefault);
    }

    private function postFrom(BlueprintInterface $blueprint): ?Post
    {
        $subject = method_exists($blueprint, 'getSubject') ? $blueprint->getSubject() : null;

        return $subject instanceof Post ? $subject : null;
    }

    /**
     * 🚨 Plain text, and it says what to do. A member who does not realise they
     * can simply reply will not, and the feature they are paying for goes
     * unused — so the instruction is in the message, not only in the docs.
     */
    private function body(Post $post, string $subject): string
    {
        $content = trim(strip_tags((string) ($post->content ?? '')));

        if (mb_strlen($content) > 1500) {
            $content = mb_substr($content, 0, 1500) . '…';
        }

        $author = $post->user?->display_name ?? 'Someone';

        return $author . " wrote:\n\n" . $content
            . "\n\n---\nReply to this email and your reply will be posted to the discussion.";
    }
}
