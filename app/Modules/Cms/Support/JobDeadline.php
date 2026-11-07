<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use Carbon\CarbonImmutable;

/**
 * Where a job post stands against its application deadline: open, closing soon, today, expired,
 * or no date. The rule belongs to the core, so a theme only draws the state it is handed; a theme
 * with no job layout shows such posts as ordinary ones.
 */
final class JobDeadline
{
    public const string OPEN = 'open';

    public const string CLOSING_SOON = 'closing_soon';

    public const string TODAY = 'today';

    public const string EXPIRED = 'expired';

    public const string NO_DATE = 'no_date';

    /** A deadline within this many days (counting from today) is "closing soon". */
    public const int CLOSING_SOON_DAYS = 7;

    /**
     * The last day applications are taken, as a day in the site's time zone; null for a post with
     * none. This is the one place that learns of the deadline column: posts have no end date yet,
     * so every post answers null until that column exists and is read here.
     */
    public function deadlineOf(Entry $post): ?CarbonImmutable // @phpstan-ignore return.unusedType (the seam: the end-date column will fill it)
    {
        return null;
    }

    /**
     * The state of a deadline seen on $today (both in the site's time zone; only the day counts).
     *
     * @return self::OPEN|self::CLOSING_SOON|self::TODAY|self::EXPIRED|self::NO_DATE
     */
    public function state(?CarbonImmutable $deadline, CarbonImmutable $today): string
    {
        if ($deadline === null) {
            return self::NO_DATE;
        }

        $days = (int) $today->startOfDay()->diffInDays($deadline->startOfDay(), false);

        return match (true) {
            $days < 0 => self::EXPIRED,
            $days === 0 => self::TODAY,
            $days <= self::CLOSING_SOON_DAYS => self::CLOSING_SOON,
            default => self::OPEN,
        };
    }
}
