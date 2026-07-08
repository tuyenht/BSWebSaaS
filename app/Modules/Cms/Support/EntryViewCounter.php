<?php

namespace App\Modules\Cms\Support;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Buffers storefront page views in Valkey so a GET never writes to Postgres (§3.1: prod GET is
 * READ ONLY). One Valkey hash per site — `entry_views:{site}` → {entry_id: count} — is bumped
 * with HINCRBY per view (O(1)); `entries:flush-views` later drains each hash into
 * entries.view_count. Every call is fail-OPEN: a Valkey hiccup must never surface on a public
 * page render, so it is swallowed and the view is simply not counted.
 */
final class EntryViewCounter
{
    private const string KEY_PREFIX = 'entry_views:';

    public function record(int $siteId, int $entryId): void
    {
        try {
            Redis::hincrby(self::KEY_PREFIX.$siteId, (string) $entryId, 1);
        } catch (Throwable) {
            // best-effort analytics — never let it break a storefront GET
        }
    }

    /**
     * Drain a site's buffered views into [entryId => count] and clear the hash. Best-effort: a
     * view landing between the read and the delete is lost (acceptable for a view tally).
     *
     * @return array<int, int>
     */
    public function drain(int $siteId): array
    {
        $key = self::KEY_PREFIX.$siteId;

        try {
            /** @var array<int|string, int|string> $counts */
            $counts = Redis::hgetall($key);
            if ($counts === []) {
                return [];
            }
            Redis::del($key);
        } catch (Throwable) {
            return [];
        }

        $drained = [];
        foreach ($counts as $entryId => $count) {
            $drained[(int) $entryId] = (int) $count;
        }

        return $drained;
    }
}
