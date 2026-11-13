<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Storefront\Models\ContactClickDaily;
use App\Modules\Theme\Chrome\FloatingContact;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
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
 * swallowed and the tap is simply not counted. A website's own channels (`c1`, `c2`, …) add new
 * fields only while its live hash holds fewer than FIELD_CAP: a script sending made-up custom
 * channels cannot grow it without bound (the flush then drops the channels the website never
 * saved), and cannot stop the built-in channels or "Mua hàng" from counting (they are bounded by
 * themselves).
 *
 * Flushing moves a website's taps through two keys: `take()` renames the live hash to
 * `contact_clicks:flushing:{site}` (taken, not yet written), `claim()` renames that to
 * `contact_clicks:applying:{site}` right before the database write, and `done()` deletes it once
 * the write committed. A taken key is safe to write again; an applying key never is (its write
 * may have committed), so a run that finds one left over drops it instead of writing it twice.
 */
final class ContactClickCounter
{
    private const string KEY_PREFIX = 'contact_clicks:';

    private const string DIRTY = 'contact_clicks:dirty';

    private const string WORKING_PREFIX = 'contact_clicks:flushing:';

    private const string APPLYING_PREFIX = 'contact_clicks:applying:';

    /**
     * Most fields one website's live hash takes before a tap on one of the website's own channels
     * may no longer add one (it is not counted; a tap on a field already there still is). Built-in
     * channels are never capped: 12 keys × 4 placements (+ "Mua hàng" in the bar) per quarter hour bound them already. A field is one (quarter hour, channel, placement): at most
     * 20 channels (FloatingContact::MAX_CHANNELS) × the 2 placements one page draws (round buttons
     * or speed dial on a computer, phone bar or bottom sheet on a phone) = 40 per quarter hour, and
     * a five-minute flush leaves one or two quarter hours in the hash. 256 keeps a fully loaded
     * website counting through more than an hour of flushes that did not run (6 quarter hours),
     * while bounding what a script can add to ~256 small fields per website between two flushes.
     */
    public const int FIELD_CAP = 256;

    /**
     * Bump a field of the live hash and mark the website dirty, in one atomic step; a NEW field of a
     * capped (custom) channel only while the hash holds fewer than the cap. KEYS: live hash, dirty
     * set. ARGV: field, cap, site, capped ('1' or '0').
     */
    private const string RECORD_SCRIPT = <<<'LUA'
        if ARGV[4] == '1' and redis.call('HEXISTS', KEYS[1], ARGV[1]) == 0 and redis.call('HLEN', KEYS[1]) >= tonumber(ARGV[2]) then
            return 0
        end
        redis.call('HINCRBY', KEYS[1], ARGV[1], 1)
        redis.call('SADD', KEYS[2], ARGV[3])
        return 1
        LUA;

    /** Keys looked at per SCAN step of the leftover sweep. */
    private const int SCAN_COUNT = 500;

    /** Seconds in one time slot of the buffer (a quarter hour). */
    public const int SLOT_SECONDS = 900;

    /**
     * The "Mua hàng" button at the end of the phone bar: counted like a built-in channel, but it is
     * no contact channel (no setting has it) and is drawn in the phone bar only.
     */
    public const string BUY = 'buy';

    /** Where the "Mua hàng" button is drawn: the phone bar only. */
    private const string BUY_PLACEMENT = 'bar';

    /**
     * A tap the counter accepts: a built-in channel or one of a website's own (`c1`, `c2`, …) in
     * one of the four placements, or the "Mua hàng" button in the phone bar.
     */
    public static function accepts(string $channel, string $placement): bool
    {
        if ($channel === self::BUY) {
            return $placement === self::BUY_PLACEMENT;
        }

        return FloatingContact::typeOf($channel) !== null && in_array($placement, ContactClickDaily::PLACEMENTS, true);
    }

    /** A channel counted on every website, whatever its setting holds: a built-in one or "Mua hàng". */
    public static function isBuiltIn(string $channel): bool
    {
        return $channel === self::BUY || isset(FloatingContact::CHANNELS[$channel]);
    }

    /** Count one tap on `channel` drawn at `placement` on the website `siteId`, at unix time `at`. */
    public function record(int $siteId, string $channel, string $placement, int $at): void
    {
        try {
            Redis::eval(
                self::RECORD_SCRIPT, 2,
                self::KEY_PREFIX.$siteId, self::DIRTY,
                intdiv($at, self::SLOT_SECONDS).'|'.$channel.'|'.$placement, self::FIELD_CAP, $siteId,
                FloatingContact::typeOf($channel) === 'custom' ? '1' : '0',
            );
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

    /**
     * The taken taps are about to be written: from here on the key must never be written again, so
     * it moves to the applying key (one RENAME). Call it right before the database write.
     */
    public function claim(int $siteId): void
    {
        // RENAME of a missing key returns false (it does not throw): another run took these taps.
        if (Redis::rename(self::WORKING_PREFIX.$siteId, self::APPLYING_PREFIX.$siteId) !== true) {
            throw new RuntimeException("The taken taps of site {$siteId} are gone: another run has them.");
        }
    }

    /** The write failed and rolled back: the taps go back to the working key for the next run. */
    public function release(int $siteId): void
    {
        if (Redis::rename(self::APPLYING_PREFIX.$siteId, self::WORKING_PREFIX.$siteId) !== true) {
            throw new RuntimeException("The claimed taps of site {$siteId} are gone: they cannot be put back.");
        }
    }

    /** The claimed taps are stored (or are to be dropped): delete them. */
    public function done(int $siteId): void
    {
        Redis::del(self::APPLYING_PREFIX.$siteId);
    }

    /**
     * One SCAN pass over this counter's keys (cursor by cursor, never KEYS), sorted by kind: `live`
     * and `taken` are websites whose taps wait in a live hash or a working key (a run that died may
     * have taken them off the dirty set), `applying` are websites with an applying key left over.
     *
     * @return array{live: list<int>, taken: list<int>, applying: list<int>}
     */
    public function leftovers(): array
    {
        $connection = Redis::connection();
        $full = Cast::string($connection->_prefix('')).self::KEY_PREFIX;
        $pattern = '/\A'.preg_quote($full, '/').'(?:(flushing|applying):)?([1-9]\d*)\z/';
        $found = ['live' => [], 'taken' => [], 'applying' => []];
        $cursor = null;

        do {
            $step = $connection->scan($cursor, ['match' => $full.'*', 'count' => self::SCAN_COUNT]);
            if (! is_array($step)) {
                break;
            }
            [$cursor, $keys] = $step;
            foreach (is_array($keys) ? $keys : [] as $key) {
                if (is_string($key) && preg_match($pattern, $key, $m) === 1) {
                    $kind = match ($m[1]) {
                        'flushing' => 'taken',
                        'applying' => 'applying',
                        default => 'live',
                    };
                    $found[$kind][(int) $m[2]] = (int) $m[2];
                }
            }
        } while ($cursor !== 0 && $cursor !== '0' && $cursor !== null);

        return array_map(array_values(...), $found);
    }

    /**
     * Drop the applying keys of these websites: their write may or may not have committed, so they
     * are never written again.
     *
     * @param  list<int>  $sites
     */
    public function dropClaims(array $sites): void
    {
        foreach (array_chunk($sites, self::SCAN_COUNT) as $chunk) {
            Redis::del(...array_map(static fn (int $site): string => self::APPLYING_PREFIX.$site, $chunk));
        }
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
            || ! self::accepts($parts[1], $parts[2])) {
            return null;
        }

        return ['at' => (int) $parts[0] * self::SLOT_SECONDS, 'channel' => $parts[1], 'placement' => $parts[2]];
    }
}
