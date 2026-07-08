<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Tenancy\Models\SiteSetting;

/**
 * Resolves the current site's permalink pattern per content section — the per-site policy that
 * decides how a URL path is composed (e.g. pages flat `/slug` vs nested `/parent/slug`). Source of
 * truth = `site_settings` (group `permalinks`, key = section, value `{"pattern": …}`); an absent
 * row means the section default. Mirrors SiteLocale: a thin per-site context reader both the write
 * path (RouteSync/PathBuilder) and the storefront read the same way, so they never disagree.
 *
 * Memoized per-site for the request. Registered scoped (TenancyServiceProvider) so the memo is
 * flushed between Octane operations and never leaks a stale pattern across requests.
 *
 * Forward-compatible with the permalink-standardization work (per-section presets); today only the
 * `pages` section is wired, defaulting to `flat` — a page does not nest under its parent by default,
 * so `parent_id` is admin tree-grouping unless the site opts into the `nested` pattern.
 */
final class PermalinkConfig
{
    private const string GROUP = 'permalinks';

    /** Per-section pattern used when the site has no `site_settings` row for that section. */
    private const array DEFAULTS = ['pages' => 'flat'];

    /** @var array<string, string> memoized "{siteId}:{section}" => pattern (request-scoped) */
    private array $memo = [];

    public function __construct(private readonly CurrentSite $current) {}

    /**
     * The permalink pattern for a content section (e.g. `pages`), falling back to the section
     * default. No site context → the default (fail-safe: never nests unexpectedly).
     */
    public function pattern(string $section): string
    {
        $default = self::DEFAULTS[$section] ?? 'flat';

        if (! $this->current->hasId()) {
            return $default;
        }

        return $this->memo[$this->current->id().':'.$section] ??= $this->resolve($section, $default);
    }

    /** Whether pages nest their URL under the parent page's path (default: no). */
    public function pagesNested(): bool
    {
        return $this->pattern('pages') === 'nested';
    }

    private function resolve(string $section, string $default): string
    {
        $value = SiteSetting::query()
            ->where('group_name', self::GROUP)
            ->where('key', $section)
            ->value('value');

        if (is_array($value) && isset($value['pattern']) && is_string($value['pattern']) && $value['pattern'] !== '') {
            return $value['pattern'];
        }

        return $default;
    }
}
