<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleModel;
use Illuminate\Support\Facades\Log;

/**
 * The current site's content locales — the single source the storefront and the admin both read,
 * so the read/write/render paths never disagree on which translation a site means.
 *
 *  - default():  the unprefixed canonical locale. Source of truth = `site_locales.is_default`
 *                (per-site, MUTABLE — design.md §10/ADR-11).
 *  - enabled():  the locales editors write in (the admin's language column + per-locale editor).
 *  - public():   the enabled locales visitors can open — storefront prefix, hreflang, sitemap.
 *
 * One query loads all three (a site has a handful of locale rows), memoized per site for the
 * request. Registered scoped (TenancyServiceProvider) so the memo is flushed between Octane
 * operations and never leaks a stale answer across requests; a writer that changes the locales
 * mid-request calls forget(). A site with no default is a broken invariant: fall back to the first
 * enabled locale (deterministic by id), then 'vi', and warn so the gap surfaces.
 */
final class SiteLocale
{
    private const string FALLBACK = 'vi';

    /** @var array<int, array{default: string, enabled: list<string>, public: list<string>}> per site id (request-scoped) */
    private array $memo = [];

    public function __construct(private readonly CurrentSite $current) {}

    public function default(): string
    {
        if (! $this->current->hasId()) {
            return self::FALLBACK;
        }

        return $this->state()['default'];
    }

    /**
     * All enabled content-locales for the current site, default first then by id — the set the
     * Pages "Ngôn ngữ" column renders and the per-locale editor offers. Empty with no site context.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        if (! $this->current->hasId()) {
            return [];
        }

        return $this->state()['enabled'];
    }

    /**
     * The enabled locales visitors can open, default first then by id. A locale can be enabled
     * (editors prepare its translations) without being public yet. Empty with no site context.
     *
     * @return list<string>
     */
    public function public(): array
    {
        if (! $this->current->hasId()) {
            return [];
        }

        return $this->state()['public'];
    }

    /** Whether editors may write in a locale on the current site. Fail-closed: no site context → false. */
    public function isEnabled(string $locale): bool
    {
        return in_array($locale, $this->enabled(), true);
    }

    /**
     * Whether visitors may open a locale on the current site (storefront prefix guard, §10/ADR-11).
     * Fail-closed: no site context → false.
     */
    public function isPublic(string $locale): bool
    {
        return in_array($locale, $this->public(), true);
    }

    /** Drop the current site's memo — for a writer that changed its locales within this request. */
    public function forget(): void
    {
        if ($this->current->hasId()) {
            unset($this->memo[$this->current->id()]);
        }
    }

    /**
     * @return array{default: string, enabled: list<string>, public: list<string>}
     */
    private function state(): array
    {
        $siteId = $this->current->id();

        return $this->memo[$siteId] ??= $this->load($siteId);
    }

    /**
     * @return array{default: string, enabled: list<string>, public: list<string>}
     */
    private function load(int $siteId): array
    {
        $default = null;
        $enabled = [];
        $public = [];

        $rows = SiteLocaleModel::query()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get(['locale', 'is_default', 'enabled', 'is_public']);

        foreach ($rows as $row) {
            $locale = (string) $row->locale;

            if ($row->is_default && $default === null) {
                $default = $locale;
            }

            if ($row->enabled) {
                $enabled[] = $locale;

                if ($row->is_public) {
                    $public[] = $locale;
                }
            }
        }

        if ($default === null || $default === '') {
            $default = $enabled[0] ?? self::FALLBACK;

            Log::warning('Site has no default locale; falling back.', ['site_id' => $siteId, 'locale' => $default]);
        }

        return ['default' => $default, 'enabled' => $enabled, 'public' => $public];
    }
}
