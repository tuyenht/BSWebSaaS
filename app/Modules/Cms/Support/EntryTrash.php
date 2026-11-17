<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How long a post or page stays in the Trash: the one number the daily purge, the list's «Tự xóa
 * vĩnh viễn sau N ngày» and the guide share (config cms.trash_retention_days, 30 unless set).
 */
final class EntryTrash
{
    /** The number used when the setting is missing or not a whole number. */
    public const int DEFAULT_DAYS = 30;

    /**
     * The day (UTC) the automatic purge started. Posts and pages already in the Trash then were never
     * told they would go, so their days count from this day, not from the day they were trashed. It
     * has no effect for anything trashed after it.
     */
    public const string SINCE = '2026-11-18';

    /** Days a trashed entry is kept; never below 1, so a mistyped setting cannot empty the Trash at once. */
    public static function days(): int
    {
        $days = config('cms.trash_retention_days', self::DEFAULT_DAYS);

        return max(1, is_int($days) ? $days : (is_numeric($days) ? (int) $days : self::DEFAULT_DAYS));
    }

    /**
     * Purge entries trashed before this moment, or null while nothing can qualify yet (the grace for
     * entries trashed before SINCE has not run out).
     */
    public static function cutoff(Carbon $now): ?Carbon
    {
        $cutoff = $now->copy()->subDays(self::days());

        return $cutoff->greaterThan(Carbon::parse(self::SINCE, 'UTC')) ? $cutoff : null;
    }

    /** Days still left before the purge: the retention minus the whole days spent in the Trash (from SINCE at the earliest). */
    public static function daysLeft(DateTimeInterface $trashedAt, DateTimeInterface $now): int
    {
        $days = self::days();
        $start = max($trashedAt->getTimestamp(), Carbon::parse(self::SINCE, 'UTC')->getTimestamp());
        $spent = intdiv(max(0, $now->getTimestamp() - $start), 86400);

        return min($days, max(0, $days - $spent));
    }
}
