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
 */
final class SiteTime
{
    private const string GROUP = 'general';

    private const string KEY = 'timezone';

    /** Platform default when a site has no valid timezone setting — VN-first SaaS (GMT+7). */
    public const string DEFAULT_ZONE = 'Asia/Ho_Chi_Minh';

    /** @var array<int, string> memoized zone per site id (request-scoped) */
    private array $memo = [];

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

        return $this->memo[$this->current->id()] ??= $this->resolve();
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

    private function resolve(): string
    {
        $value = SiteSetting::query()
            ->where('group_name', self::GROUP)
            ->where('key', self::KEY)
            ->value('value');

        if (is_array($value) && isset($value['tz']) && is_string($value['tz']) && $this->isValidZone($value['tz'])) {
            return $value['tz'];
        }

        return self::DEFAULT_ZONE;
    }

    private function isValidZone(string $tz): bool
    {
        return in_array($tz, DateTimeZone::listIdentifiers(), true);
    }
}
