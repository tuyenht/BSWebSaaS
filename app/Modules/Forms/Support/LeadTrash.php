<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How long a lead stays in the «Thùng rác» of «Khách liên hệ»: the one number the daily purge
 * (`leads:purge-trash`), the Trash tab's «còn N ngày» and the detail's band share. Every Trash of the
 * admin keeps its items 30 days.
 */
final class LeadTrash
{
    public const int DAYS = 30;

    /**
     * The day (UTC) leads could first be moved to the Trash. Nothing can have been trashed before it,
     * so it only keeps the first purge from running before a whole window has passed since then.
     */
    public const string SINCE = '2026-11-23';

    /** Purge leads trashed before this moment, or null while nothing can qualify yet. */
    public static function cutoff(Carbon $now): ?Carbon
    {
        $cutoff = $now->copy()->subDays(self::DAYS);

        return $cutoff->greaterThan(Carbon::parse(self::SINCE, 'UTC')) ? $cutoff : null;
    }

    /** Days still left before the purge: the window minus the whole days spent in the Trash. */
    public static function daysLeft(DateTimeInterface $trashedAt, DateTimeInterface $now): int
    {
        $start = max($trashedAt->getTimestamp(), Carbon::parse(self::SINCE, 'UTC')->getTimestamp());
        $spent = intdiv(max(0, $now->getTimestamp() - $start), 86400);

        return min(self::DAYS, max(0, self::DAYS - $spent));
    }
}
