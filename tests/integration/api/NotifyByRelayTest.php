<?php

namespace Ernestdefoe\Courier\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Courier\Relay\RelayClient;
use Ernestdefoe\Courier\Reply\ReplyGrant;
use Ernestdefoe\Courier\Tests\integration\FakeRelay;
use Flarum\Discussion\Discussion;
use Flarum\Locale\LocaleManager;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use GuzzleHttp\Psr7\Request;
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

        // The test forum resolves the locale manager before extensions' Locales
        // extenders hook it, so no extension's strings load here (mentions'
        // neither). Register Courier's file the way that extender would.
        $this->app()->getContainer()->make(LocaleManager::class)->addTranslations('en', __DIR__.'/../../../resources/locale/en.yml');

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
        $this->assertStringContainsString('Reply to this email', $calls[0]['body']['body']);
        $this->assertMatchesRegularExpression('#/notifications/unsubscribe/2/\w{60}#', $calls[0]['body']['body'], 'The same one-click unsubscribe as core\'s emails');
        $this->assertStringContainsString('/settings', $calls[0]['body']['body']);

        $this->assertSame(
            [[2, 1]],
            $this->database()->table('courier_reply_grants')->get()->map(fn ($g) => [(int) $g->user_id, (int) $g->discussion_id])->all(),
            'The forum records that it emailed member 2 about discussion 1, so their reply can be posted'
        );
    }

    #[Test]
    public function a_second_notification_refreshes_the_grant_instead_of_adding_one()
    {
        $this->connect();
        $this->relay($this->json(['sent' => true]));

        $this->mentionNormalUser();
        $this->database()->table('courier_reply_grants')->update(['granted_at' => Carbon::now()->subDays(20)]);

        // As the driver records it for the next notification about this discussion.
        ReplyGrant::grant([2], 1);

        $grants = $this->database()->table('courier_reply_grants')->get();
        $this->assertCount(1, $grants);
        $this->assertTrue(Carbon::parse($grants[0]->granted_at)->isAfter(Carbon::now()->subDay()));
    }

    #[Test]
    public function an_unreachable_relay_falls_back_and_is_not_asked_again_for_a_minute()
    {
        $this->connect();
        $this->relay(new ConnectException('Connection refused', new Request('POST', 'https://relay.example')));

        $this->mentionNormalUser();

        $this->assertCount(1, $this->relayCalls());
        $this->assertSame(
            2,
            $this->database()->table('unsubscribe_tokens')->where('user_id', 2)->count(),
            'One link in the relay attempt, one in the ordinary email core sent instead'
        );

        $relay = $this->app()->getContainer()->make(RelayClient::class);
        $this->assertFalse($relay->available(), 'Marked down for a minute');
        $this->assertNull($relay->post('mail/notify', []));
        $this->assertCount(1, $this->relayCalls(), 'Not asked again while it is down');
    }

    #[Test]
    public function while_the_relay_is_down_only_the_ordinary_email_is_built()
    {
        $this->connect();
        $this->relay();
        $this->app()->getContainer()->make(CacheRepository::class)->put('courier.relay_down', true, 60);

        $this->mentionNormalUser();

        $this->assertSame([], $this->relayCalls());
        $this->assertSame(1, $this->database()->table('unsubscribe_tokens')->where('user_id', 2)->count(), 'No token left over from a relay attempt');
    }

    #[Test]
    public function an_unconfirmed_address_is_never_handed_to_the_relay()
    {
        $this->connect();
        $this->relay($this->json(['sent' => true]));

        $this->mentionNormalUser(['is_email_confirmed' => false]);

        $this->assertSame([], $this->relayCalls());
        $this->assertSame(0, $this->database()->table('courier_reply_grants')->count(), 'Nobody emailed, nobody granted');
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
