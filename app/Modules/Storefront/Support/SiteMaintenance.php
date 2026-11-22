<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Theme\Theming\StorefrontSettings;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A website's maintenance mode (Cài đặt › Bảo mật & Nâng cao), stored in site_settings group
 * `storefront`, key `maintenance`:
 *
 *   {"on": bool, "messages": {"<locale>": "<the owner's words>", …}, "reopens_at": "<UTC ISO 8601>"|null}
 *
 * While it is on, a visitor who is not a signed-in member of the website gets the maintenance page
 * (SiteStatePage) in place of every storefront page. The reopen time only tells visitors when to
 * come back (the words and Retry-After): the website never opens by itself when it comes.
 *
 * Read through the storefront's one settings read (StorefrontSettings), so checking it costs a page
 * no query of its own. shape() is the one reading of a stored value, for the admin screen too.
 */
final readonly class SiteMaintenance
{
    public const string GROUP = 'storefront';

    public const string KEY = 'maintenance';

    /** Longest message kept per language. */
    public const int MAX_MESSAGE = 500;

    /** Retry-After when no reopen time is set, or once it has passed. */
    public const int DEFAULT_RETRY_AFTER = 3600;

    public function __construct(private StorefrontSettings $settings) {}

    /**
     * The stored value in its one shape: unknown keys dropped, messages trimmed strings per
     * language, the reopen time a UTC instant or null.
     *
     * @return array{on: bool, messages: array<string, string>, reopens_at: CarbonImmutable|null}
     */
    public static function shape(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];

        $messages = [];
        foreach (is_array($stored['messages'] ?? null) ? $stored['messages'] : [] as $locale => $text) {
            if (is_string($locale) && is_string($text) && trim($text) !== '') {
                $messages[$locale] = mb_substr(trim($text), 0, self::MAX_MESSAGE);
            }
        }

        $reopens = null;
        if (is_string($stored['reopens_at'] ?? null) && $stored['reopens_at'] !== '') {
            try {
                $reopens = CarbonImmutable::parse($stored['reopens_at'])->utc();
            } catch (Throwable) {
                $reopens = null;
            }
        }

        return ['on' => ($stored['on'] ?? false) === true, 'messages' => $messages, 'reopens_at' => $reopens];
    }

    /**
     * The current website's saved maintenance setting, on or off.
     *
     * @return array{on: bool, messages: array<string, string>, reopens_at: CarbonImmutable|null}
     */
    public function saved(): array
    {
        return self::shape($this->settings->get(self::GROUP, self::KEY));
    }

    /**
     * The current website's maintenance setting while it is on, else null.
     *
     * @return array{on: true, messages: array<string, string>, reopens_at: CarbonImmutable|null}|null
     */
    public function active(): ?array
    {
        $shape = $this->saved();

        return $shape['on'] ? $shape : null;
    }

    public function on(): bool
    {
        return $this->active() !== null;
    }

    /** The website's time zone, from the same settings read. */
    public function zone(): string
    {
        return SiteTime::zoneFrom($this->settings->get('general', 'timezone'));
    }

    /** The reopen time while it is still ahead, else null (a passed time is no longer told). */
    public static function ahead(?CarbonImmutable $reopens, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        return $reopens !== null && $reopens->greaterThan($now ?? CarbonImmutable::now()) ? $reopens : null;
    }

    /** Longest Retry-After: a reopen time far ahead still has crawlers look again daily. */
    public const int MAX_RETRY_AFTER = 86400;

    /** Seconds a crawler should wait: until the reopen time while it is ahead (a day at most), else an hour. */
    public static function retryAfter(?CarbonImmutable $reopens, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $ahead = self::ahead($reopens, $now);

        return $ahead === null ? self::DEFAULT_RETRY_AFTER : min(self::MAX_RETRY_AFTER, max(60, $ahead->getTimestamp() - $now->getTimestamp()));
    }

    /** A reopen time as visitors and the admin read it: the website's local day and hour (22/11/2026 15:00). */
    public static function format(CarbonImmutable $at, string $zone): string
    {
        return $at->setTimezone($zone)->format('d/m/Y H:i');
    }
}
