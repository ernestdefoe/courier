<?php

namespace Ernestdefoe\Courier\Console;

use Ernestdefoe\Courier\Relay\RelayClient;
use Ernestdefoe\Courier\Reply\CollectedReply;
use Ernestdefoe\Courier\Reply\ReplyGrant;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\PostResource;
use Flarum\Console\AbstractCommand;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum courier:collect` — fetch replies from the relay and post them.
 *
 * 🚨 Returns int, not void — see any other Flarum console command.
 */
class CollectRepliesCommand extends AbstractCommand
{
    /** The most replies posted per run, whatever the relay hands back. */
    private const BATCH = 50;

    public function __construct(
        protected RelayClient $relay,
        protected JsonApi $api,
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

        if (! $dry) {
            // Here, on the schedule, rather than while sending a notification.
            ReplyGrant::prune();
            CollectedReply::prune();
        }

        $response = $this->relay->post('mail/poll', []);

        if ($response === null) {
            $this->reportFailure();

            return 0;
        }

        $messages = array_slice(array_values(array_filter(
            (array) ($response['messages'] ?? []),
            fn ($m) => is_array($m) && (int) ($m['id'] ?? 0) > 0
        )), 0, self::BATCH);

        if ($dry) {
            $this->reportDryRun($messages);

            return 0;
        }

        $handled = $this->deliver($messages);

        if ($handled && $this->relay->post('mail/poll', ['ack' => $handled]) === null) {
            // Safe: what was posted is remembered (see deliver()), and the relay
            // offers it again next run only to have it acknowledged then.
            $this->error('Posted, but the service was not told. It will be told on the next run.');
        }

        $this->info('Posted '.$this->replies(count($handled)).'.');

        return 0;
    }

    /**
     * 🚨 Tell the two apart. A refusal will never fix itself — a wrong key, or
     * an origin the key is not bound to — while an outage resolves on its own.
     * Reporting both as "could not reach" sends somebody looking at their
     * network for a configuration problem.
     */
    private function reportFailure(): void
    {
        if ($this->relay->lastRefusal() !== null) {
            $this->error('The service refused this forum ('.$this->relay->lastRefusal().'). Check the site key, and that it was issued for this domain.');
        } else {
            $this->error('Could not reach the service. Nothing was lost — replies stay queued.');
        }
    }

    /** @param list<array<string, mixed>> $messages */
    private function reportDryRun(array $messages): void
    {
        foreach ($messages as $message) {
            $this->info('Would post reply '.(int) $message['id'].' to discussion '.(int) ($message['discussionId'] ?? 0));
        }

        $this->info('Would post '.$this->replies(count($messages)).'.');
    }

    private function replies(int $n): string
    {
        return $n.' repl'.($n === 1 ? 'y' : 'ies');
    }

    /**
     * Post a batch, with its members (and their groups, for the permission
     * check), discussions and grants loaded in a fixed number of queries
     * however many replies it holds.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<int> every message id handled, posted or refused
     */
    private function deliver(array $messages): array
    {
        $userIds = array_values(array_unique(array_map(fn ($m) => (int) ($m['userId'] ?? 0), $messages)));
        $discussionIds = array_values(array_unique(array_map(fn ($m) => (int) ($m['discussionId'] ?? 0), $messages)));

        $users = User::query()->with('groups')->whereIn('id', $userIds)->get()->keyBy('id');
        $discussions = Discussion::query()->whereIn('id', $discussionIds)->get()->keyBy('id');
        $grants = ReplyGrant::recent($userIds, $discussionIds);

        /*
         * 🚨 A reply already posted is never posted twice. If the ack after a
         * run fails, the relay offers the same messages again; without this
         * each would become a second post.
         */
        $seen = CollectedReply::seen(array_map(fn ($m) => (int) $m['id'], $messages));

        $handled = [];

        foreach ($messages as $message) {
            $user = $users->get((int) ($message['userId'] ?? 0));
            $discussion = $discussions->get((int) ($message['discussionId'] ?? 0));
            $body = trim((string) ($message['body'] ?? ''));

            if (isset($seen[(int) $message['id']])) {
                // Posted on an earlier run; only the ack went missing.
                $handled[] = (int) $message['id'];

                continue;
            }

            if (! $user || ! $discussion || $body === '') {
                $this->log->info('[courier] dropping an empty reply, or one for a missing member or discussion', [
                    'user' => (int) ($message['userId'] ?? 0),
                    'discussion' => (int) ($message['discussionId'] ?? 0),
                ]);
            } elseif ($this->allowed($user, $discussion, $grants) && $this->post($user, $discussion, $body)) {
                CollectedReply::remember((int) $message['id']);
            }

            /*
             * 🚨 Acknowledged even when refused or not posted. A reply to a
             * deleted discussion, or from a member who has left, can never
             * succeed; left unacked it would be fetched and fail on every run.
             */
            $handled[] = (int) $message['id'];
        }

        return $handled;
    }

    /**
     * @param array<string, true> $grants
     */
    private function allowed(User $user, Discussion $discussion, array $grants): bool
    {
        $context = ['user' => $user->id, 'discussion' => $discussion->id];

        /*
         * 🚨 The relay's word is not enough to post as someone. The forum only
         * posts a reply from a member it emailed about this discussion
         * recently (ReplyGrant), so the relay cannot pick the author.
         */
        if (! isset($grants[$user->id.':'.$discussion->id])) {
            $this->log->warning('[courier] refusing a reply from a member this forum did not email about this discussion', $context);

            return false;
        }

        /*
         * 🚨 Permissions are re-checked here, not assumed from the fact that we
         * emailed them. A member can be suspended, or lose access to a tag,
         * between the notification going out and the reply arriving — and an
         * email round-trip is exactly long enough for that to happen.
         */
        if (! $user->can('reply', $discussion)) {
            $this->log->info('[courier] refusing a reply the member may no longer make', $context);

            return false;
        }

        return true;
    }

    private function post(User $user, Discussion $discussion, string $body): bool
    {
        try {
            /*
             * 🚨 Through the API's own create endpoint, as the member — not a
             * hand-built CommentPost. Everything that screens a new post hangs
             * off the Saving event that endpoint fires: flarum/approval holds
             * the post there for anyone without "reply without approval", and
             * other moderation extensions do the same.
             *
             * The endpoint also runs the formatter as the member, fires Posted
             * and marks the discussion read for them, exactly as a reply typed
             * on the forum. process() does not run the endpoint's permission
             * check, which is why allowed() asks can('reply') first.
             */
            $this->api->forResource(PostResource::class)->forEndpoint('create')->process([
                'data' => [
                    'type' => 'posts',
                    'attributes' => ['content' => $body],
                    'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussion->id]]],
                ],
            ], [], ['actor' => $user]);
        } catch (\Throwable $e) {
            $this->log->error('[courier] could not post a reply: '.$e->getMessage(), ['user' => $user->id, 'discussion' => $discussion->id]);

            return false;
        }

        return true;
    }
}
