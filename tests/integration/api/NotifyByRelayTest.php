<?php

namespace Ernestdefoe\Courier\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Courier\Tests\integration\FakeRelay;
use Flarum\Discussion\Discussion;
use Flarum\Mentions\Notification\UserMentionedBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * A mention is a notification about a post, so it can be answered: its email
 * goes out through the relay. Without a connection, or for a member the forum
 * would not email, the relay is never handed the address.
 *
 * The notification is sent through Flarum's NotificationSyncer, exactly as
 * flarum/mentions sends it, so the test does not depend on post formatting.
 */
class NotifyByRelayTest extends TestCase
{
    use FakeRelay;
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-mentions', 'ernestdefoe-courier');

        $earlier = Carbon::now()->subHour();

        $this->prepareDatabase([
            User::class => [
                // Mention emails are off by default; this member turned them on.
                ['preferences' => json_encode(['notify_userMentioned_email' => true])] + $this->normalUser(),
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Mentioned', 'created_at' => $earlier, 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 2, 'last_post_number' => 2],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Start</p></t>', 'created_at' => $earlier],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>New information for you</p></t>', 'created_at' => $earlier],
            ],
        ]);
    }

    /** Call after connect() and relay(): it boots the app. */
    private function mentionNormalUser(array $userChanges = []): void
    {
        if ($userChanges) {
            $this->database()->table('users')->where('id', 2)->update($userChanges);
        }

        $this->app()->getContainer()->make(NotificationSyncer::class)->sync(
            new UserMentionedBlueprint(Post::query()->find(2)),
            [User::query()->find(2)]
        );
    }

    #[Test]
    public function a_mention_email_goes_through_the_relay_so_it_can_be_answered()
    {
        $this->connect();
        $this->relay($this->json(['sent' => true]));

        $this->mentionNormalUser();

        $calls = $this->relayCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('/api/steward/v1/mail/notify', $calls[0]['path']);
        $this->assertSame(2, $calls[0]['body']['userId']);
        $this->assertSame(1, $calls[0]['body']['discussionId']);
        $this->assertSame(2, $calls[0]['body']['postId']);
        $this->assertSame('normal@machine.local', $calls[0]['body']['to']['address']);
        $this->assertStringContainsString('New information for you', $calls[0]['body']['body']);
    }

    #[Test]
    public function an_unconfirmed_address_is_never_handed_to_the_relay()
    {
        $this->connect();
        $this->relay($this->json(['sent' => true]));

        $this->mentionNormalUser(['is_email_confirmed' => false]);

        $this->assertSame([], $this->relayCalls());
    }

    #[Test]
    public function a_member_who_turned_these_emails_off_gets_none()
    {
        $this->connect();
        $this->relay($this->json(['sent' => true]));

        $this->mentionNormalUser(['preferences' => json_encode(['notify_userMentioned_email' => false])]);

        $this->assertSame([], $this->relayCalls());
    }

    #[Test]
    public function without_a_connection_the_relay_is_not_used()
    {
        $this->relay($this->json(['sent' => true]));

        $this->mentionNormalUser();

        $this->assertSame([], $this->relayCalls());
    }
}
