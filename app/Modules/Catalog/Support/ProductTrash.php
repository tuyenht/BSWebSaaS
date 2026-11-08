<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How long a product stays in the Trash: the one number the nightly purge and the products list share.
 */
final class ProductTrash
{
    /** Days a product is kept in the Trash before it is deleted for good. */
    public const int DAYS = 30;

    /**
     * The day (UTC) the automatic purge started. Products already in the Trash then were never shown a
     * countdown, so their 30 days count from this day, not from the day they were trashed. It has no
     * effect for anything trashed after it: those follow the plain 30 days.
     */
    public const string SINCE = '2026-11-09';

    /**
     * Purge products trashed before this moment, or null while nothing can qualify yet (the grace for
     * products trashed before SINCE has not run out).
     */
    public static function cutoff(Carbon $now): ?Carbon
    {
        $cutoff = $now->copy()->subDays(self::DAYS);

        return $cutoff->greaterThan(Carbon::parse(self::SINCE, 'UTC')) ? $cutoff : null;
    }

    /** Days still left before the purge: 30 minus the whole days spent in the Trash (counted from SINCE at the earliest), within 0..30. */
    public static function daysLeft(DateTimeInterface $trashedAt, DateTimeInterface $now): int
    {
        $start = max($trashedAt->getTimestamp(), Carbon::parse(self::SINCE, 'UTC')->getTimestamp());
        $spent = intdiv($now->getTimestamp() - $start, 86400);

        return min(self::DAYS, max(0, self::DAYS - $spent));
    }
}
