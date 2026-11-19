<?php

namespace Ernestdefoe\Courier\Reply;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * The forum's own record that it emailed a member about a discussion.
 *
 * 🚨 This is what stops the relay choosing who a reply is from. The relay says
 * which member and discussion a reply belongs to; the forum posts it only when
 * it emailed that member about that discussion within the last LIFETIME_DAYS.
 * A compromised relay, or a stolen site key, can then at most answer an email
 * a member was really sent — never post as an arbitrary member.
 *
 * @property int    $id
 * @property int    $user_id
 * @property int    $discussion_id
 * @property Carbon $granted_at
 */
class ReplyGrant extends AbstractModel
{
    /** How long a member can answer a notification email by replying to it. */
    public const LIFETIME_DAYS = 30;

    protected $table = 'courier_reply_grants';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'discussion_id' => 'integer',
        'granted_at' => 'datetime',
    ];

    public static function cutoff(): Carbon
    {
        return Carbon::now()->subDays(self::LIFETIME_DAYS);
    }

    /**
     * Record (or refresh) that these members were emailed about a discussion,
     * in one statement per 500 members.
     *
     * @param list<int> $userIds
     */
    public static function grant(array $userIds, int $discussionId): void
    {
        if (! $userIds) {
            return;
        }

        $now = Carbon::now();

        // Chunked so a discussion with thousands of subscribers stays under
        // every database's limit on bound parameters per statement.
        foreach (array_chunk($userIds, 500) as $chunk) {
            static::query()->upsert(
                array_map(fn (int $id) => ['user_id' => $id, 'discussion_id' => $discussionId, 'granted_at' => $now], $chunk),
                ['user_id', 'discussion_id'],
                ['granted_at']
            );
        }
    }

    /**
     * The member/discussion pairs among these that the forum emailed recently,
     * as "user:discussion" keys, in one query.
     *
     * @param list<int> $userIds
     * @param list<int> $discussionIds
     * @return array<string, true>
     */
    public static function recent(array $userIds, array $discussionIds): array
    {
        if (! $userIds || ! $discussionIds) {
            return [];
        }

        return static::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('discussion_id', $discussionIds)
            ->where('granted_at', '>=', static::cutoff())
            ->get(['user_id', 'discussion_id'])
            ->mapWithKeys(fn (self $g) => [$g->user_id.':'.$g->discussion_id => true])
            ->all();
    }

    /** Forget grants too old to be answered. Run from the scheduled collector. */
    public static function prune(): void
    {
        static::query()->where('granted_at', '<', static::cutoff())->delete();
    }
}
