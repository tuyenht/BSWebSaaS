<?php

declare(strict_types=1);

namespace App\Modules\Cms\Validation;

use App\Modules\Shared\Support\Cast;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * «Gỡ khỏi website lúc» for a post, page or product: empty (it stays), or a moment still to come and
 * after its scheduled publish time when it has one («Hẹn giờ đăng»), so it is public for a while at
 * least. The value is the moment itself, or the form's local time («Y-m-d\TH:i», a datetime-local
 * field) read in the site's time zone. The database keeps the same rule (a CHECK that the end is after
 * published_at), so no road around the form stores one that ends before it starts.
 */
final readonly class ExpiryTime implements ValidationRule
{
    /** The form's way of writing a moment, read in the site's zone. */
    public const string FORMAT = 'Y-m-d\TH:i';

    public function __construct(private string $zone, private ?DateTimeInterface $publishAt = null) {}

    /** The moment $value names (a time, or the form's local time in $zone); null when empty or unreadable. */
    public static function parse(mixed $value, string $zone): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $at = CarbonImmutable::createFromFormat('!'.self::FORMAT, trim($value), $zone);
        } catch (Throwable) {
            return null;
        }

        return $at instanceof CarbonImmutable && $at->format(self::FORMAT) === trim($value) ? $at : null;
    }

    /**
     * Why $at cannot be the end time (the sentence to show), or null when it can. $publishAt is the
     * scheduled publish time when there is one still to come.
     */
    public static function error(?CarbonImmutable $at, ?DateTimeInterface $publishAt, string $zone, ?CarbonImmutable $now = null): ?string
    {
        if ($at === null) {
            return null;
        }
        if ($at <= ($now ?? CarbonImmutable::now())) {
            return Cast::string(__('entries.expiry_past'));
        }
        if ($publishAt !== null && $at <= $publishAt) {
            return Cast::string(__('entries.expiry_before_publish', [
                'time' => CarbonImmutable::instance($publishAt)->setTimezone($zone)->format('H:i d/m/Y'),
            ]));
        }

        return null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        $at = self::parse($value, $this->zone);
        if ($at === null) {
            $fail(Cast::string(__('entries.expiry_invalid')));

            return;
        }

        $error = self::error($at, $this->publishAt, $this->zone);
        if ($error !== null) {
            $fail($error);
        }
    }
}
