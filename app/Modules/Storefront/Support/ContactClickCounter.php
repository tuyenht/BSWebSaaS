<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Storefront\Models\ContactClickDaily;
use App\Modules\Theme\Chrome\FloatingContact;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Buffers taps on the storefront contact buttons in Valkey, so a tap never writes to Postgres:
 * one hash per website, `contact_clicks:{site}` → {"{quarter}|{channel}|{placement}": count},
 * bumped with HINCRBY, plus the set `contact_clicks:dirty` of the websites that have any.
 * `contact:flush-clicks` later turns each hash into contact_click_daily rows.
 *
 * `quarter` is the tap's time in quarter hours since the epoch (unix time ÷ 900): the website's
 * calendar day is worked out from it when the buffer is flushed, in the website's own timezone,
 * so the tap itself reads no setting (every UTC offset in use is a whole number of quarter hours).
 *
 * Recording is fail-OPEN: a Valkey hiccup must never surface on a visitor's tap, so it is
 * swallowed and the tap is simply not counted.
 */
final class ContactClickCounter
{
    private const string KEY_PREFIX = 'contact_clicks:';

    private const string DIRTY = 'contact_clicks:dirty';

    private const string WORKING_PREFIX = 'contact_clicks:flushing:';

    /** Seconds in one time slot of the buffer (a quarter hour). */
    public const int SLOT_SECONDS = 900;

    /** A channel the counter accepts: a built-in channel or one of a website's own (`c1`, `c2`, …). */
    public static function isChannel(string $channel): bool
    {
        return FloatingContact::typeOf($channel) !== null;
    }

    public static function isPlacement(string $placement): bool
    {
        return in_array($placement, ContactClickDaily::PLACEMENTS, true);
    }

    /** Count one tap on `channel` drawn at `placement` on the website `siteId`, at unix time `at`. */
    public function record(int $siteId, string $channel, string $placement, int $at): void
    {
        try {
            Redis::hincrby(self::KEY_PREFIX.$siteId, intdiv($at, self::SLOT_SECONDS).'|'.$channel.'|'.$placement, 1);
            Redis::sadd(self::DIRTY, (string) $siteId);
        } catch (Throwable) {
            // best-effort tally: never let it break a visitor's tap
        }
    }

    /**
     * Up to `count` websites with buffered taps, taken off the dirty set.
     *
     * @return list<int>
     */
    public function popDirty(int $count): array
    {
        $popped = Redis::spop(self::DIRTY, $count);
        if (! is_array($popped)) {
            return [];
        }

        $sites = [];
        foreach ($popped as $id) {
            $site = Cast::int($id);
            if ($site > 0) {
                $sites[] = $site;
            }
        }

        return $sites;
    }

    /** How many websites have buffered taps now. */
    public function dirtyCount(): int
    {
        return Cast::int(Redis::scard(self::DIRTY));
    }

    /** Mark websites as having buffered taps (after a flush that failed for them). */
    public function markDirty(int ...$siteIds): void
    {
        if ($siteIds !== []) {
            Redis::sadd(self::DIRTY, ...array_map(strval(...), $siteIds));
        }
    }

    /**
     * Take a website's buffered taps for flushing: the hash is renamed to the website's working key
     * in one step, so a tap arriving meanwhile starts a fresh hash instead of being lost between the
     * read and the delete. A working key left by a flush that died is taken first, as it is; `more`
     * then says the live hash still waits, and the caller marks the website dirty once its run is
     * over. Empty counts when there is nothing.
     *
     * @return array{counts: array<string, int>, more: bool}
     */
    public function take(int $siteId): array
    {
        $live = self::KEY_PREFIX.$siteId;
        $working = self::WORKING_PREFIX.$siteId;
        $more = false;

        if (Cast::int(Redis::exists($working)) > 0) {
            $more = Cast::int(Redis::exists($live)) > 0;
        } elseif (Cast::int(Redis::exists($live)) === 0 || Redis::rename($live, $working) !== true) {
            return ['counts' => [], 'more' => false];
        }

        $raw = Redis::hgetall($working);
        $counts = [];
        foreach (is_array($raw) ? $raw : [] as $field => $count) {
            $counts[(string) $field] = Cast::int($count);
        }

        return ['counts' => $counts, 'more' => $more];
    }

    /** The taken taps are stored: drop the working key. */
    public function done(int $siteId): void
    {
        Redis::del(self::WORKING_PREFIX.$siteId);
    }

    /**
     * A buffered field read back: the tap's slot start (unix time), channel and placement; null for
     * a field this counter never writes.
     *
     * @return array{at: int, channel: string, placement: string}|null
     */
    public static function parse(string $field): ?array
    {
        $parts = explode('|', $field);
        if (count($parts) !== 3 || preg_match('/\A[1-9]\d{0,11}\z/', $parts[0]) !== 1
            || ! self::isChannel($parts[1]) || ! self::isPlacement($parts[2])) {
            return null;
        }

        return ['at' => (int) $parts[0] * self::SLOT_SECONDS, 'channel' => $parts[1], 'placement' => $parts[2]];
    }
}
