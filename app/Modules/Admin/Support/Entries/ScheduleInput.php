<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;

/**
 * A «Hẹn giờ đăng» field (a datetime-local value in the website's clock): read into UTC, judged with the
 * two sentences the list's dialog and the editor both say, and its first value. TransitionEntryStatus
 * judges the time again on the locked row (SchedulePastException).
 */
final class ScheduleInput
{
    /** The field's time in UTC; null when it is empty or not a time (only a forged or broken submit sends that). */
    public static function parse(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return app(SiteTime::class)->toStorage($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** Why the time cannot be used, or null: an empty (or unreadable) field, or a time that is not after now. */
    public static function error(string $value): ?string
    {
        $when = self::parse($value);
        if ($when === null) {
            return Cast::string(__('entries.sched_err_empty'));
        }

        return $when->isFuture() ? null : Cast::string(__('entries.sched_err_past'));
    }

    /** The field's first value: tomorrow 08:00 in the website's clock. */
    public static function tomorrowEight(): string
    {
        return app(SiteTime::class)->now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i');
    }
}
