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
 * Only a day changed in this save is judged: a post already stored with a later day (from before this
 * rule) still saves while its day is left as it is.
 */
final readonly class DisplayDateNotAfterToday implements ValidationRule
{
    /** @param  string  $unchanged  the day as stored; a value equal to it passes */
    public function __construct(private string $zone, private string $unchanged = '') {}

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
        if (is_string($value) && $value !== $this->unchanged && self::isFuture($value, $this->zone)) {
            // the message names today on the website's clock, as the editor's board does
            $fail(Cast::string(__('entries.ed.pd_future', ['date' => CarbonImmutable::now()->setTimezone($this->zone)->format('d/m/Y')])));
        }
    }
}
