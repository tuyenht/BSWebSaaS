<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The single funnel for every user-facing date read/write. Times are STORED in UTC (timestamptz,
 * D11) but PARSED and RENDERED in the site's display zone, so an instant never shows under two
 * clocks. Source of truth = `site_settings` (group `general`, key `timezone`, value
 * `{"tz": "Asia/Ho_Chi_Minh"}`); an absent or invalid value means the platform default (GMT+7),
 * and an admin may override it per site. Mirrors SiteLocale/PermalinkConfig: a thin per-site
 * context both the write path (scheduling) and the render path read the same way.
 *
 * Memoized per-site for the request. Registered scoped (TenancyServiceProvider) so the memo is
 * flushed between Octane operations and never leaks a stale zone across requests.
 *
 * Contract: do NOT call Carbon::parse()/->format() directly on a user-facing datetime — route the
 * write through toStorage() (naive local → UTC) and the read through forDisplay() (UTC → site zone).
 *
 * The storefront's day format rides the same read: `site_settings` general/date_format
 * (`{"format": "dmy"}`, one of DATE_FORMATS). "auto" — also what an absent or unknown value means —
 * prints the day as the storefront always has (31/12/2026); day() is the one place a visitor-facing
 * day is printed.
 */
final class SiteTime
{
    private const string GROUP = 'general';

    private const string KEY = 'timezone';

    private const string FORMAT_KEY = 'date_format';

    /** Platform default when a site has no valid timezone setting — VN-first SaaS (GMT+7). */
    public const string DEFAULT_ZONE = 'Asia/Ho_Chi_Minh';

    /** How the storefront prints a day: auto (the storefront's own 31/12/2026), d/m/Y, m/d/Y, ISO, or the month's name in the page's language. */
    public const array DATE_FORMATS = ['auto', 'dmy', 'mdy', 'iso', 'long'];

    /** @var array<int, string> memoized zone per site id (request-scoped) */
    private array $memo = [];

    /** @var array<int, string> memoized day format per site id (request-scoped) */
    private array $formats = [];

    public function __construct(private readonly CurrentSite $current) {}

    /**
     * The current site's display timezone (IANA), falling back to the platform default. No site
     * context → the default (fail-safe: a valid zone is always returned).
     */
    public function zone(): string
    {
        if (! $this->current->hasId()) {
            return self::DEFAULT_ZONE;
        }

        $this->load();

        return $this->memo[$this->current->id()] ?? self::DEFAULT_ZONE;
    }

    /** The current site's storefront day format (one of DATE_FORMATS); "auto" without a site. */
    public function dateFormat(): string
    {
        if (! $this->current->hasId()) {
            return 'auto';
        }
        $this->load();

        return $this->formats[$this->current->id()] ?? 'auto';
    }

    /** Drop the memo, after the zone or the day format was just changed in this request. */
    public function forget(): void
    {
        $this->memo = [];
        $this->formats = [];
    }

    /** A stored UTC instant as the site's local day, in the site's format and the page's language. */
    public function day(?DateTimeInterface $utc, string $locale): string
    {
        $local = $this->forDisplay($utc);

        return $local === null ? '' : self::formatDay($local, $this->dateFormat(), $locale);
    }

    /**
     * A day in one of DATE_FORMATS ("auto" and anything unknown print 31/12/2026). "long" names the
     * month in $locale (31 tháng 12 năm 2026 · December 31, 2026 · 2026年12月31日).
     */
    public static function formatDay(DateTimeInterface $day, string $format, string $locale): string
    {
        $day = CarbonImmutable::instance($day);
        $named = $day->locale($locale);

        return match ($format) {
            'mdy' => $day->format('m/d/Y'),
            'iso' => $day->format('Y-m-d'),
            'long' => $named instanceof CarbonImmutable ? $named->isoFormat('LL') : $day->format('d/m/Y'),
            default => $day->format('d/m/Y'),
        };
    }

    /**
     * Parse a NAIVE local datetime (e.g. a `datetime-local` form value) as the site's zone and
     * return it in UTC for storage. An instant that already carries a zone is just normalised to
     * UTC. The single write funnel — callers store the result as-is.
     */
    public function toStorage(string|DateTimeInterface $local): CarbonImmutable
    {
        if ($local instanceof DateTimeInterface) {
            return CarbonImmutable::instance($local)->utc();
        }

        return CarbonImmutable::parse($local, $this->zone())->utc();
    }

    /** Convert a stored UTC instant into the site's zone for rendering. The single read funnel. */
    public function forDisplay(?DateTimeInterface $utc): ?CarbonImmutable
    {
        return $utc === null ? null : CarbonImmutable::instance($utc)->setTimezone($this->zone());
    }

    /** "Now" in the site's zone — a `datetime-local` picker floor that reads in local wall-clock. */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->zone());
    }

    /** Zone and day format in one read, memoized for the request. */
    private function load(): void
    {
        $site = $this->current->id();
        if (isset($this->memo[$site])) {
            return;
        }

        $values = SiteSetting::query()
            ->where('group_name', self::GROUP)
            ->whereIn('key', [self::KEY, self::FORMAT_KEY])
            ->pluck('value', 'key');

        $zone = $values[self::KEY] ?? null;
        $this->memo[$site] = is_array($zone) && isset($zone['tz']) && is_string($zone['tz']) && $this->isValidZone($zone['tz'])
            ? $zone['tz']
            : self::DEFAULT_ZONE;

        $format = $values[self::FORMAT_KEY] ?? null;
        $this->formats[$site] = is_array($format) && in_array($format['format'] ?? null, self::DATE_FORMATS, true)
            ? (string) $format['format']
            : 'auto';
    }

    private function isValidZone(string $tz): bool
    {
        return in_array($tz, DateTimeZone::listIdentifiers(), true);
    }
}
