<?php

namespace Ernestdefoe\Courier\Job;

use Ernestdefoe\Courier\Notification\RelayMailer;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Notification\NotificationMailer;
use Flarum\Post\CommentPost;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * One notification email for one member, through the relay — the counterpart
 * of core's SendEmailNotificationJob, queued the same way, one per recipient.
 */
class RelayNotificationJob extends AbstractJob
{
    public function __construct(
        private readonly MailableInterface&BlueprintInterface $blueprint,
        private readonly User $recipient,
        private readonly CommentPost $post
    ) {
        parent::__construct();
    }

    public function handle(RelayMailer $relay, NotificationMailer $mailer, CacheRepository $cache): void
    {
        /*
         * The same guard as core's SendEmailNotificationJob: a notification
         * synced twice in quick succession (Posted, then Revised) queues two
         * identical jobs, and only the first should send.
         */
        $store = $cache->getStore();
        $lock = $store instanceof LockProvider ? $store->lock($this->lockKey(), 600) : null;

        if ($lock && ! $lock->get()) {
            return;
        }

        try {
            /*
             * 🚨 Anyone the relay does not take is sent the ordinary way. They
             * lose the ability to reply by email for that one notification,
             * which is a far smaller failure than never hearing about it.
             */
            if (! $relay->send($this->blueprint, $this->recipient, $this->post)) {
                $mailer->send($this->blueprint, $this->recipient);
            }
        } catch (\Throwable $e) {
            // Nothing was sent, so a retry of this job must not find it locked.
            $lock?->release();

            throw $e;
        }
    }

    private function lockKey(): string
    {
        $data = $this->blueprint->getData();

        return 'courier.notification.sent:'.md5(implode(':', [
            $this->blueprint::getType(),
            $this->blueprint->getFromUser()->id ?? '',
            $this->post->id,
            $data === null ? '' : md5((string) json_encode($data)),
        ])).':'.$this->recipient->id;
    }
}
