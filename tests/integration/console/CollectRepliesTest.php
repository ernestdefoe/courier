<?php

namespace Ernestdefoe\Courier\Tests\integration\console;

use Carbon\Carbon;
use Ernestdefoe\Courier\Tests\integration\FakeRelay;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * courier:collect turns queued email replies into posts, as the member who
 * sent them, and only where that member may still reply.
 */
class CollectRepliesTest extends ConsoleTestCase
{
    use FakeRelay;
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-courier');

        $earlier = Carbon::now()->subHour();

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'suspended', 'email' => 'suspended@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MEMBER_ID]],
            Discussion::class => [
                ['id' => 1, 'title' => 'Open', 'created_at' => $earlier, 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
                ['id' => 2, 'title' => 'Private', 'created_at' => $earlier, 'user_id' => 1, 'first_post_id' => 2, 'comment_count' => 1, 'last_post_number' => 1, 'is_private' => true],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Start</p></t>', 'created_at' => $earlier],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Start</p></t>', 'created_at' => $earlier],
            ],
        ]);
    }

    private function collect(array $options = []): string
    {
        return $this->runCommand(['command' => 'courier:collect'] + $options);
    }

    private function replies(): array
    {
        return $this->database()->table('posts')->where('number', '>', 1)->orderBy('id')
            ->get(['discussion_id', 'user_id'])->map(fn ($p) => [(int) $p->discussion_id, (int) $p->user_id])->all();
    }

    #[Test]
    public function nothing_happens_until_the_forum_is_connected()
    {
        $this->relay();

        $this->assertStringContainsString('not connected', $this->collect());
        $this->assertSame([], $this->relayCalls());
    }

    #[Test]
    public function only_an_https_service_is_trusted_with_the_site_key()
    {
        $this->connect();
        $this->setting('courier.relay_url', 'http://relay.example');
        $this->relay();

        $this->assertStringContainsString('not connected', $this->collect());
        $this->assertSame([], $this->relayCalls());
    }

    #[Test]
    public function a_reply_is_posted_as_its_author_and_acknowledged()
    {
        $this->connect();
        $this->relay(
            $this->json(['messages' => [['id' => 7, 'userId' => 2, 'discussionId' => 1, 'body' => 'Agreed, from my inbox.']]]),
            $this->json(['messages' => []])
        );

        $this->assertStringContainsString('Posted 1 reply', $this->collect());

        $this->assertSame([[1, 2]], $this->replies());
        $calls = $this->relayCalls();
        $this->assertSame('/api/steward/v1/mail/poll', $calls[0]['path']);
        $this->assertSame('Bearer test-site-key', $calls[0]['auth']);
        $this->assertSame(['ack' => [7]], $calls[1]['body']);
    }

    #[Test]
    public function a_reply_the_member_may_not_make_is_dropped_not_retried_forever()
    {
        $this->connect();
        $this->relay(
            $this->json(['messages' => [
                ['id' => 8, 'userId' => 2, 'discussionId' => 2, 'body' => 'Into a private discussion'],
                ['id' => 9, 'userId' => 99, 'discussionId' => 1, 'body' => 'From a deleted account'],
            ]]),
            $this->json(['messages' => []])
        );

        $this->collect();

        $this->assertSame([], $this->replies());
        $this->assertSame(['ack' => [8, 9]], $this->relayCalls()[1]['body'], 'Acknowledged, so the relay stops offering them');
    }

    #[Test]
    public function a_dry_run_posts_and_acknowledges_nothing()
    {
        $this->connect();
        $this->relay($this->json(['messages' => [['id' => 7, 'userId' => 2, 'discussionId' => 1, 'body' => 'Hello']]]));

        $this->assertStringContainsString('Would post reply 7 to discussion 1', $this->collect(['--dry-run' => true]));
        $this->assertSame([], $this->replies());
        $this->assertCount(1, $this->relayCalls());
    }

    #[Test]
    public function a_refused_site_key_is_reported()
    {
        $this->connect();
        $this->relay($this->json(['error' => 'unknown_site'], 401));

        $this->assertStringContainsString('refused this forum (unknown_site)', $this->collect());
        $this->assertSame([], $this->replies());
    }
}
