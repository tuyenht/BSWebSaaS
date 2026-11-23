<?php

declare(strict_types=1);

namespace App\Modules\Forms\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteSettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * «Thời hạn lưu giữ liên hệ» of «Khách liên hệ»: how long a website keeps a lead before the daily
 * `leads:purge-old` deletes it for good (12, 24 or 36 months, or never), and the spam rule beside
 * it. Stored in site_settings group `forms`, key `lead_retention`:
 *
 *   {"months": 12|24|36|null, "was": 12|24|36|null, "shortened_at": "2026-11-23T08:00:00+00:00"|null}
 *
 * No row means DEFAULT_MONTHS. `months` null is «Không tự xóa». A shorter period does not apply at
 * once: for WAIT_DAYS after `shortened_at` the purge keeps using `was` (the period in force before),
 * so a wrong click can still be taken back before anything goes. A longer period applies at once.
 *
 * Spam (status `spam`) goes once both its sending and its last change are SPAM_DAYS old, whatever
 * the period (the board: «Thư rác luôn tự dọn sau 30 ngày»), so a lead marked spam today is kept a
 * month even when it was sent long ago. Nothing is deleted before SINCE + WAIT_DAYS: the arrival of
 * the rule counts as a shortening from «never» for every website.
 */
final class LeadRetention
{
    public const string GROUP = 'forms';

    public const string KEY = 'lead_retention';

    /** The periods offered, in months, in the board's order; null is «Không tự xóa». */
    public const array CHOICES = [12, 24, 36, null];

    public const int DEFAULT_MONTHS = 24;

    /** Days a shortened period waits before it deletes anything. */
    public const int WAIT_DAYS = 7;

    /** Days a lead marked spam is kept after both its sending and its last change. */
    public const int SPAM_DAYS = 30;

    /** The day (UTC) the rule reached the websites: no purge runs before WAIT_DAYS after it. */
    public const string SINCE = '2026-11-24';

    /** The choice as the dialog sends it: '12', '24', '36' or 'none'. */
    public static function key(?int $months): string
    {
        return $months === null ? 'none' : (string) $months;
    }

    /** The months a dialog choice stands for; false when it is not one of the choices. */
    public static function fromKey(string $key): int|false|null
    {
        foreach (self::CHOICES as $months) {
            if (self::key($months) === $key) {
                return $months;
            }
        }

        return false;
    }

    /**
     * The stored value in its one shape. No row (null) is the default period; a value that is not
     * one of the choices reads as `valid: false`, and the purge then deletes nothing of that website for age.
     *
     * @return array{valid: bool, months: int|null, was: int|null, shortened_at: CarbonImmutable|null}
     */
    public static function shape(mixed $stored): array
    {
        if ($stored === null) {
            return ['valid' => true, 'months' => self::DEFAULT_MONTHS, 'was' => null, 'shortened_at' => null];
        }
        $stored = Cast::arr($stored);
        $months = $stored['months'] ?? null;
        $was = $stored['was'] ?? null;
        if (! array_key_exists('months', $stored) || ! in_array($months, self::CHOICES, true) || ! in_array($was, self::CHOICES, true)) {
            return ['valid' => false, 'months' => null, 'was' => null, 'shortened_at' => null];
        }
        $at = null;
        if (is_string($stored['shortened_at'] ?? null)) {
            try {
                $at = CarbonImmutable::parse($stored['shortened_at']);
            } catch (Throwable) {
                return ['valid' => false, 'months' => null, 'was' => null, 'shortened_at' => null];
            }
        }

        /** @var int|null $months */
        /** @var int|null $was */
        return ['valid' => true, 'months' => $months, 'was' => $at === null ? null : $was, 'shortened_at' => $at];
    }

    /**
     * The current website's setting, read through Tenancy's SiteSettings (one indexed read of site_settings, under RLS).
     *
     * @return array{valid: bool, months: int|null, was: int|null, shortened_at: CarbonImmutable|null}
     */
    public static function current(): array
    {
        return self::shape(app(SiteSettings::class)->value(self::GROUP, self::KEY));
    }

    /**
     * The period the purge uses at $now: the chosen one, or the one before it while a shortening
     * still waits. null = nothing is deleted for age.
     *
     * @param  array{valid: bool, months: int|null, was: int|null, shortened_at: CarbonImmutable|null}  $setting
     */
    public static function inForce(array $setting, DateTimeInterface $now): ?int
    {
        if (! $setting['valid']) {
            return null;
        }
        $at = $setting['shortened_at'];
        if ($at !== null && $at->addDays(self::WAIT_DAYS)->getTimestamp() > $now->getTimestamp()) {
            return $setting['was'];
        }

        return $setting['months'];
    }

    /** Whether $to keeps leads for less time than $from (null = for ever). */
    public static function shorter(?int $to, ?int $from): bool
    {
        return $to !== null && ($from === null || $to < $from);
    }

    /**
     * The value to store when a member picks $to over $setting at $now: a period shorter than the
     * one in force starts (again) the wait, keeping the one in force meanwhile; anything else applies
     * at once.
     *
     * @param  array{valid: bool, months: int|null, was: int|null, shortened_at: CarbonImmutable|null}  $setting
     * @return array{months: int|null, was: int|null, shortened_at: string|null}
     */
    public static function next(array $setting, ?int $to, CarbonImmutable $now): array
    {
        // an unreadable value deletes nothing (the purge leaves the website alone): as if «never»
        $inForce = $setting['valid'] ? self::inForce($setting, $now) : null;
        if (self::shorter($to, $inForce)) {
            return ['months' => $to, 'was' => $inForce, 'shortened_at' => $now->utc()->toIso8601String()];
        }

        return ['months' => $to, 'was' => null, 'shortened_at' => null];
    }

    /** Delete leads sent before this moment, or null when nothing goes for age. */
    public static function cutoff(?int $months, DateTimeInterface $now): ?CarbonImmutable
    {
        if ($months === null || ! self::started($now)) {
            return null;
        }

        return CarbonImmutable::instance($now)->subMonthsNoOverflow($months);
    }

    /** Delete spam sent and last changed before this moment, or null while the rule has not started. */
    public static function spamCutoff(DateTimeInterface $now): ?CarbonImmutable
    {
        return self::started($now) ? CarbonImmutable::instance($now)->subDays(self::SPAM_DAYS) : null;
    }

    private static function started(DateTimeInterface $now): bool
    {
        return CarbonImmutable::parse(self::SINCE, 'UTC')->addDays(self::WAIT_DAYS)->getTimestamp() <= $now->getTimestamp();
    }
}
