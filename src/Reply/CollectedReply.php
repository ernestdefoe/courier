<?php

namespace Ernestdefoe\Courier\Reply;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * A reply this forum has already posted, by the relay's id for it.
 *
 * 🚨 A table, not the cache: `php flarum cache:clear` empties the cache, and a
 * reply whose acknowledgement went missing would then be posted a second time.
 *
 * @property int    $id
 * @property int    $relay_message_id
 * @property Carbon $collected_at
 */
class CollectedReply extends AbstractModel
{
    /** Long past any relay redelivery, which happens on the very next run. */
    public const KEEP_DAYS = 7;

    protected $table = 'courier_collected_replies';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'relay_message_id' => 'integer',
        'collected_at' => 'datetime',
    ];

    /**
     * The relay ids among these that were posted already, in one query.
     *
     * @param list<int> $relayIds
     * @return array<int, true>
     */
    public static function seen(array $relayIds): array
    {
        if (! $relayIds) {
            return [];
        }

        $posted = static::query()->whereIn('relay_message_id', $relayIds)->pluck('relay_message_id')->all();

        return array_fill_keys(array_map('intval', $posted), true);
    }

    public static function remember(int $relayId): void
    {
        static::query()->insertOrIgnore(['relay_message_id' => $relayId, 'collected_at' => Carbon::now()]);
    }

    /** Run from the scheduled collector. */
    public static function prune(): void
    {
        static::query()->where('collected_at', '<', Carbon::now()->subDays(self::KEEP_DAYS))->delete();
    }
}
