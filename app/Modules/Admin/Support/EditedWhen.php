<?php

namespace App\Modules\Admin\Support;

use Carbon\CarbonImmutable;

/**
 * The short words a record list prints for its last edit, in the website's own clock: "Sửa hôm nay,
 * 10:20", "Sửa hôm qua, 08:15", then either "Sửa 3 ngày trước" for the last few days or the day
 * itself. Both times come already in the website's zone (SiteTime), so "today" is the website's
 * day, not the server's.
 *
 * $recentDays words the days up to that many ago as "N ngày trước" (1 keeps only today and
 * yesterday). $dayThisYear writes an older edit of this year as "Sửa 30/07, 14:32"; otherwise every
 * older edit reads "Sửa 30/07/2026".
 */
final class EditedWhen
{
    public static function short(CarbonImmutable $at, CarbonImmutable $now, int $recentDays = 1, bool $dayThisYear = true): string
    {
        $days = (int) round($at->startOfDay()->diffInDays($now->startOfDay()));
        $time = $at->format('H:i');

        return match (true) {
            // a day still ahead (a wrong clock, an imported date) is written out, never called today
            $days < 0 => __('admin.list.edited_other', ['date' => $at->format('d/m/Y')]),
            $days === 0 => __('admin.list.edited_today', ['time' => $time]),
            $days === 1 => __('admin.list.edited_yesterday', ['time' => $time]),
            $days <= $recentDays => __('admin.list.edited_days', ['n' => $days]),
            $dayThisYear && $at->year === $now->year => __('admin.list.edited_year', ['day' => $at->format('d/m'), 'time' => $time]),
            default => __('admin.list.edited_other', ['date' => $at->format('d/m/Y')]),
        };
    }

    /** The whole moment, for the <time> element's title and for a screen reader: "16/11/2026 · 10:20". */
    public static function full(CarbonImmutable $at): string
    {
        return $at->format('d/m/Y').' · '.$at->format('H:i');
    }
}
