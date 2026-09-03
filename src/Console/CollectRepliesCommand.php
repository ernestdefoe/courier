<?php

namespace Ernestdefoe\Courier\Console;

use Ernestdefoe\Courier\Relay\RelayClient;
use Flarum\Console\AbstractCommand;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Carbon\Carbon;
use Flarum\Post\Event\Posted;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum courier:collect` — fetch replies from the relay and post them.
 *
 * 🚨 Returns int, not void — see any other Flarum console command.
 */
class CollectRepliesCommand extends AbstractCommand
{
    public function __construct(
        protected RelayClient $relay,
        protected EventDispatcher $events,
        protected LoggerInterface $log
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('courier:collect')
            ->setDescription('Fetch email replies from the relay and post them.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be posted, post nothing');
    }

    protected function fire(): int
    {
        if (! $this->relay->configured()) {
            $this->info('Courier is not connected — nothing to collect.');

            return 0;
        }

        $dry = (bool) $this->input->getOption('dry-run');

        $response = $this->relay->post('mail/poll', []);

        if ($response === null) {
            /*
             * 🚨 Tell the two apart. A refusal will never fix itself — a wrong
             * key, or an origin the key is not bound to — while an outage
             * resolves on its own. Reporting both as "could not reach" sends
             * somebody looking at their network for a configuration problem.
             */
            if ($this->relay->lastRefusal !== null) {
                $this->error('The service refused this forum (' . $this->relay->lastRefusal . '). Check the site key, and that it was issued for this domain.');
            } else {
                $this->error('Could not reach the service. Nothing was lost — replies stay queued.');
            }

            return 0;
        }

        $messages = (array) ($response['messages'] ?? []);
        $posted   = [];

        foreach ($messages as $message) {
            $id = (int) ($message['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            if ($dry) {
                $this->info('Would post reply ' . $id . ' to discussion ' . (int) ($message['discussionId'] ?? 0));

                continue;
            }

            if ($this->post($message)) {
                $posted[] = $id;

                continue;
            }

            /*
             * 🚨 Acknowledged even when we could not post it.
             *
             * A reply naming a discussion that has since been deleted, or a
             * member who has left, can never succeed — leaving it unacked means
             * fetching and failing on it forever, on every run, until somebody
             * notices the log. Refusing it once and moving on is the only
             * version that terminates.
             */
            $posted[] = $id;
        }

        if ($posted && ! $dry) {
            // Acknowledge in the next call, which also collects anything new.
            $this->relay->post('mail/poll', ['ack' => $posted]);
        }

        $this->info(($dry ? 'Would post ' : 'Posted ') . count($posted) . ' repl' . (count($posted) === 1 ? 'y' : 'ies') . '.');

        return 0;
    }

    private function post(array $message): bool
    {
        $userId       = (int) ($message['userId'] ?? 0);
        $discussionId = (int) ($message['discussionId'] ?? 0);
        $body         = trim((string) ($message['body'] ?? ''));

        if ($body === '' || $userId <= 0 || $discussionId <= 0) {
            return false;
        }

        $user       = User::find($userId);
        $discussion = Discussion::find($discussionId);

        if (! $user || ! $discussion) {
            $this->log->info('[courier] dropping a reply for a missing user or discussion', [
                'user'       => $userId,
                'discussion' => $discussionId,
            ]);

            return false;
        }

        /*
         * 🚨 Permissions are re-checked here, not assumed from the fact that we
         * emailed them. A member can be suspended, or lose access to a tag,
         * between the notification going out and the reply arriving — and an
         * email round-trip is exactly long enough for that to happen.
         */
        if (! $user->can('reply', $discussion)) {
            $this->log->info('[courier] refusing a reply the member may no longer make', [
                'user'       => $userId,
                'discussion' => $discussionId,
            ]);

            return false;
        }

        try {
            $post = new CommentPost();
            $post->discussion_id = $discussion->id;
            $post->user_id       = $user->id;
            $post->type          = 'comment';
            $post->created_at    = Carbon::now();

            /*
             * 🚨 Through setContentAttribute, so the formatter runs. Writing
             * to the column directly stores raw text where parsed markup is
             * expected, and the post then renders as whatever the renderer
             * makes of unparsed input.
             */
            $post->setContentAttribute($body, $user);
            $post->save();

            $discussion->refreshCommentCount();
            $discussion->refreshLastPost();
            $discussion->save();

            /*
             * 🚨 Fire Posted. Everything that reacts to a new post hangs off
             * this — notifications to other participants, search indexing, and
             * moderation screening. A reply that arrives by email is a post
             * like any other, and skipping the event would make it the one
             * post on the forum that nothing sees.
             */
            $this->events->dispatch(new Posted($post, $user));
        } catch (\Throwable $e) {
            $this->log->error('[courier] could not post a reply: ' . $e->getMessage());

            return false;
        }

        return true;
    }
}
