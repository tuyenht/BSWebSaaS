<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Tenancy\Models\SiteLocale as SiteLocaleModel;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the current site's default locale — the single source the storefront and the
 * admin both read, so the read/write/render paths never disagree on which translation a
 * site means. Source of truth = `site_locales.is_default` (per-site, MUTABLE — design.md
 * §10/ADR-11); `sites.default_locale` was a legacy mirror, dropped in the contract migration.
 *
 * Memoized per-site for the request. Registered scoped (TenancyServiceProvider) so the
 * memo is flushed between Octane operations and never leaks a stale default across
 * requests. A site with no default is a broken invariant: fall back to the first enabled
 * locale (deterministic by id), then 'vi', and warn so the gap surfaces.
 */
final class SiteLocale
{
    private const string FALLBACK = 'vi';

    /** @var array<int, string> memoized default per site id (request-scoped) */
    private array $memo = [];

    /** @var array<int, list<string>> memoized enabled locales per site id (request-scoped) */
    private array $enabledMemo = [];

    public function __construct(private readonly CurrentSite $current) {}

    public function default(): string
    {
        if (! $this->current->hasId()) {
            return self::FALLBACK;
        }

        $siteId = $this->current->id();

        return $this->memo[$siteId] ??= $this->resolve($siteId);
    }

    /**
     * All enabled content-locales for the current site, default first then by id — the set the
     * Pages "Ngôn ngữ" column renders and the per-locale editor offers. Empty with no site context.
     * Memoized per-site for the request (flushed between Octane operations, like the default memo).
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        if (! $this->current->hasId()) {
            return [];
        }

        $siteId = $this->current->id();

        if (isset($this->enabledMemo[$siteId])) {
            return $this->enabledMemo[$siteId];
        }

        $list = [];
        foreach (SiteLocaleModel::query()->where('enabled', true)->orderByDesc('is_default')->orderBy('id')->get() as $row) {
            $list[] = (string) $row->locale;
        }

        return $this->enabledMemo[$siteId] = $list;
    }

    /**
     * Whether a locale is enabled for the current site (storefront prefix guard, §10/ADR-11).
     * Fail-closed: no site context → false.
     */
    public function isEnabled(string $locale): bool
    {
        if (! $this->current->hasId()) {
            return false;
        }

        return SiteLocaleModel::query()->where('locale', $locale)->where('enabled', true)->exists();
    }

    private function resolve(int $siteId): string
    {
        $default = SiteLocaleModel::query()->where('is_default', true)->value('locale');

        if (is_string($default) && $default !== '') {
            return $default;
        }

        $fallback = SiteLocaleModel::query()->where('enabled', true)->orderBy('id')->value('locale');
        $locale = is_string($fallback) && $fallback !== '' ? $fallback : self::FALLBACK;

        Log::warning('Site has no default locale; falling back.', ['site_id' => $siteId, 'locale' => $locale]);

        return $locale;
    }
}
