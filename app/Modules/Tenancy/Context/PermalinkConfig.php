<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Tenancy\Models\SiteSetting;

/**
 * Resolves the current site's permalink pattern per content section — the per-site policy that
 * decides how a URL path is composed. Source of truth = `site_settings` (group `permalinks`, key =
 * section, value `{"pattern": …, "base": …}`); an absent row means the section default (flat).
 * Mirrors SiteLocale/SiteTime: a thin per-site context both the write path (RouteSync/PathBuilder)
 * and the storefront read the same way, so they never disagree.
 *
 * Presets and where each is valid (per section TYPE — a preset outside its type's set is ignored and
 * falls back to flat, never acted on):
 *   - pages    : flat (default) · nested · base
 *   - posts    : flat (default) · base · category · category_path
 *   - products : flat (default) · base · category · category_path
 * Every section defaults to `flat` (no prefix, no parent- or group-inheritance); prefixing is
 * strictly opt-in. The `base` preset reads its static segment from the same row's `value.base`.
 *
 * Memoized per-site for the request and negative-cached — a section with no row costs at most one
 * query. Registered scoped (TenancyServiceProvider) so the memo is flushed between Octane operations
 * and never leaks a stale pattern across requests.
 */
final class PermalinkConfig
{
    private const string GROUP = 'permalinks';

    /** The universal default: every section is flat unless the site opts into a prefixing preset. */
    public const string DEFAULT_PATTERN = 'flat';

    /**
     * Valid presets per section type. `flat` is always valid (and the default); the rest are opt-in.
     * A stored pattern outside its type's set is ignored → flat. An unknown type is fail-closed to
     * flat only.
     *
     * @var array<string, list<string>>
     */
    private const array VALID = [
        'pages' => ['flat', 'nested', 'base'],
        'posts' => ['flat', 'base', 'category', 'category_path'],
        'products' => ['flat', 'base', 'category', 'category_path'],
    ];

    /** @var array<string, array<mixed>|null> memoized stored value per "{siteId}:{section}" */
    private array $memo = [];

    public function __construct(private readonly CurrentSite $current) {}

    /**
     * The permalink pattern for a section of the given type (e.g. `('posts', 'posts')` — `$section`
     * is the stable ContentSection.key, the same identifier pages use, NOT a taxonomy slug),
     * validated against the type's allowed set and falling back to `flat`. No site context, no row,
     * or an unrecognised/invalid stored value → flat (fail-safe: never prefixes unexpectedly).
     */
    public function pattern(string $section, string $type): string
    {
        $stored = $this->stored($section) ?? [];
        $pattern = $stored['pattern'] ?? null;

        if (is_string($pattern) && in_array($pattern, $this->allowedPatterns($type), true)) {
            return $pattern;
        }

        return self::DEFAULT_PATTERN;
    }

    /**
     * The static base segment for the `base` preset (`value.base`, e.g. `san-pham`), or null when
     * unset/blank. Surrounding whitespace and slashes are trimmed; canonical slug-normalisation
     * belongs to the settings writer.
     */
    public function base(string $section): ?string
    {
        $stored = $this->stored($section) ?? [];
        $base = $stored['base'] ?? null;

        if (! is_string($base)) {
            return null;
        }

        $base = trim(trim($base), '/');

        return $base === '' ? null : $base;
    }

    /**
     * The presets a section of this type may use (the validity matrix) — for the settings UI and for
     * validating a stored value. An unknown type is fail-closed to flat only.
     *
     * @return list<string>
     */
    public function allowedPatterns(string $type): array
    {
        return self::VALID[$type] ?? [self::DEFAULT_PATTERN];
    }

    /** Whether pages nest their URL under the parent page's path (default: no). */
    public function pagesNested(): bool
    {
        return $this->pattern('pages', 'pages') === 'nested';
    }

    /**
     * Drop the memoized rows so the next read reflects settings written earlier in the SAME
     * request/command — e.g. a permalink reflow rewrites `site_settings[permalinks]` and then
     * re-syncs routes in one process; without this the re-sync would compose from the stale
     * pattern this instance already cached. Scoped instance, so callers share the cleared memo.
     */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * The memoized raw `value` row for a section (null with no site context or no row). Negative-
     * cached so a section without a setting costs at most one query per request.
     *
     * @return array<mixed>|null
     */
    private function stored(string $section): ?array
    {
        if (! $this->current->hasId()) {
            return null;
        }

        $key = $this->current->id().':'.$section;

        if (! array_key_exists($key, $this->memo)) {
            $value = SiteSetting::query()
                ->where('group_name', self::GROUP)
                ->where('key', $section)
                ->value('value');

            $this->memo[$key] = is_array($value) ? $value : null;
        }

        return $this->memo[$key];
    }
}
