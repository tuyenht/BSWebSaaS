<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleModel;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\Log;

/**
 * The current site's content locales — the single source the storefront and the admin both read,
 * so the read/write/render paths never disagree on which translation a site means.
 *
 *  - default():  the unprefixed canonical locale. Source of truth = `site_locales.is_default`
 *                (per-site, MUTABLE — design.md §10/ADR-11).
 *  - enabled():  the locales editors write in (the admin's language column + per-locale editor).
 *  - public():   the enabled locales visitors can open — storefront prefix, hreflang, sitemap.
 *  - formerDefaults(): the public locales that were the default before (most recent first), whose
 *                old clean addresses the storefront sends on under their prefix.
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

    /** Where a change of default records the language that stepped down (site_settings group/key). */
    public const string FORMER_GROUP = 'locales';

    public const string FORMER_KEY = 'former_defaults';

    /** @var array<int, array{default: string, enabled: list<string>, public: list<string>}> per site id (request-scoped) */
    private array $memo = [];

    /** @var array<int, list<string>> per site id (request-scoped) */
    private array $former = [];

    public function __construct(private readonly CurrentSite $current) {}

    /**
     * Whether a lower-case path segment is shaped like a language code — `en`, `vi`, `pt-br` — the
     * way the storefront reads a first segment as a language prefix. Shape only: isPublic() says
     * whether visitors may open it. The two-letter base keeps ordinary slugs (`tin-tuc`) out.
     */
    public static function looksLikeCode(string $segment): bool
    {
        return preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $segment) === 1;
    }

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
     * Language codes in the website's own order (default first, then by id); codes it does not know follow, alphabetically.
     * The one order every screen and action uses to pick "the first" of several languages.
     *
     * @param  iterable<string>  $codes
     * @return list<string>
     */
    public function inSiteOrder(iterable $codes): array
    {
        $held = [];
        foreach ($codes as $code) {
            $held[(string) $code] = true;
        }
        $out = [];
        foreach ($this->enabled() as $code) {
            if (isset($held[$code])) {
                $out[] = $code;
                unset($held[$code]);
            }
        }
        $rest = array_keys($held);
        sort($rest);

        return [...$out, ...$rest];
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

    /**
     * The public languages other than the default that were the site's default before, the most
     * recent first: a clean path the default misses may be one of their old addresses. Read only
     * when the site has a second public language (a one-language site never pays the query), and
     * only on the miss that asks. Empty with no site context.
     *
     * @return list<string>
     */
    public function formerDefaults(): array
    {
        if (! $this->current->hasId() || count($this->public()) < 2) {
            return [];
        }

        return $this->former[$this->current->id()] ??= $this->loadFormer();
    }

    /** Drop the current site's memo — for a writer that changed its locales within this request. */
    public function forget(): void
    {
        if ($this->current->hasId()) {
            unset($this->memo[$this->current->id()], $this->former[$this->current->id()]);
        }
    }

    /**
     * @return list<string>
     */
    private function loadFormer(): array
    {
        $value = SiteSetting::query()->where('group_name', self::FORMER_GROUP)->where('key', self::FORMER_KEY)->value('value');
        $codes = is_array($value) && is_array($value['codes'] ?? null) ? $value['codes'] : [];
        $default = $this->default();

        return array_values(array_filter(
            array_unique(array_filter($codes, 'is_string')),
            fn (string $code): bool => $code !== $default && $this->isPublic($code),
        ));
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
