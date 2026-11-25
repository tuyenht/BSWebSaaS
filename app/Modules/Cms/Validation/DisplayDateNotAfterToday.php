<?php

declare(strict_types=1);

namespace App\Modules\Cms\Validation;

use App\Modules\Cms\Support\DisplayDate;
use App\Modules\Shared\Support\Cast;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * «Ngày đăng hiện trên bài» may be any real day up to today in the site's time zone, never a day to
 * come: a post dated in the future misleads readers and gives search engines a datePublished that has
 * not happened. A past day is fine (an old article put back up under its first date). Empty passes:
 * it means the real publish day. A day that is not a real calendar day is DisplayDate's own error.
 */
final readonly class DisplayDateNotAfterToday implements ValidationRule
{
    public function __construct(private string $zone) {}

    /** Whether $day (Y-m-d) is after today in $zone; false for an empty or unreadable day. */
    public static function isFuture(string $day, string $zone, ?CarbonImmutable $now = null): bool
    {
        if (! DisplayDate::valid($day)) {
            return false;
        }

        return $day > ($now ?? CarbonImmutable::now())->setTimezone($zone)->format('Y-m-d');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::isFuture($value, $this->zone)) {
            $fail(Cast::string(__('entries.ed.pd_future')));
        }
    }
}
