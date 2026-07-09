<?php

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\SiteLocale;

/**
 * Composes an entry's canonical URL path (design.md §213: "path = danh mục + slug") from the
 * per-site permalink preset. A page is flat `/slug` by default (home slug → `/`); a post is flat
 * too, unless its section opts into a prefixing preset (category / category_path / base). The
 * section — and thus the preset — is derived from the post's primary category via SectionResolver.
 * Category prefixes are built from the per-locale taxonomy slug (NOT the ltree `path`, which is
 * structural/query-only) so localized URLs stay correct.
 *
 * This is the single forward-composer and the sole locale-prefix chokepoint; the storefront read
 * surfaces resolve inbound paths against the STORED routes RouteSync persists from here.
 */
final readonly class PathBuilder
{
    /** Conventional home slug → site root (matches ProvisionSite + seedHomepage). */
    public const string HOME_SLUG = 'home';

    public function __construct(
        private SiteLocale $siteLocale,
        private PermalinkConfig $permalinks,
        private SectionResolver $sections,
    ) {}

    /**
     * Overlay the locale prefix on an UNPREFIXED, site-relative path (design.md §10/ADR-11):
     * the single chokepoint for locale-prefixing. The site's CURRENT default locale (read
     * dynamically — no hardcoded 'vi') stays unprefixed; every other locale is prefixed
     * `/{locale}`, with the home path `/` becoming `/{locale}` (no trailing slash).
     */
    public function publicUrl(string $path, string $locale): string
    {
        if ($locale === $this->siteLocale->default()) {
            return $path;
        }

        $path = '/'.ltrim($path, '/');

        return $path === '/' ? '/'.$locale : '/'.$locale.$path;
    }

    public function forEntry(Entry $entry, string $locale, string $slug): string
    {
        $slug = trim($slug, '/');

        if ($entry->type === 'page') {
            return $this->pagePath($entry, $locale, $slug);
        }

        return $this->postPath($entry, $locale, $slug);
    }

    /**
     * A post's path per its section's permalink preset (default flat `/slug`):
     *   category      → /{primary-category}/slug
     *   category_path → /{ancestor}/…/{primary-category}/slug
     *   base          → /{configured-base}/slug
     * The section (hence the preset) is derived from the post's primary category; a post with no
     * resolvable section — or a preset whose prefix cannot be built — falls back to flat.
     */
    private function postPath(Entry $entry, string $locale, string $slug): string
    {
        $section = $this->sections->forEntry($entry);

        $pattern = $section !== null
            ? $this->permalinks->pattern($section['key'], $section['type'])
            : PermalinkConfig::DEFAULT_PATTERN;

        $prefix = match ($pattern) {
            'category' => $this->categoryPrefix($entry, $locale),
            'category_path' => $this->categoryPath($entry, $locale),
            'base' => $section !== null ? $this->basePrefix($section['key']) : '',
            default => '',
        };

        return $prefix.'/'.$slug;
    }

    /**
     * A page's path. Flat `/slug` (home → `/`) by default; the site's `pages` permalink preset
     * decides — `nested` prefixes the page with its parent page's path (`/dich-vu/thiet-ke-web`),
     * `base` prefixes a fixed segment (`/{base}/slug`). Under flat a `parent_id` is admin
     * tree-grouping only and never nests the URL. When nested, the chain is composed from ancestor
     * SLUGS — not the stored route — so it is correct no matter which ancestors are published yet,
     * and a visited-set guards against a parent cycle.
     *
     * @param  array<int, true>  $seen
     */
    private function pagePath(Entry $entry, string $locale, string $slug, array $seen = []): string
    {
        if ($slug === '' || $slug === self::HOME_SLUG) {
            return '/';
        }

        $pattern = $this->permalinks->pattern('pages', 'pages');

        if ($pattern === 'base') {
            return $this->basePrefix('pages').'/'.$slug;
        }

        $seen[$entry->id] = true;
        $parent = $pattern === 'nested'
            && $entry->parent_id !== null
            && ! isset($seen[$entry->parent_id])
                ? $entry->parent
                : null;

        if ($parent instanceof Entry && $parent->type === 'page') {
            $translation = $parent->translationFor($locale);
            $parentSlug = $translation !== null ? trim((string) $translation->slug, '/') : '';
            $parentPath = $this->pagePath($parent, $locale, $parentSlug, $seen);

            return ($parentPath === '/' ? '' : $parentPath).'/'.$slug;
        }

        return '/'.$slug;
    }

    /**
     * A taxonomy's canonical path: flat `/slug` built from its localized slug (the
     * category-list page, e.g. `/tin-tuc`). Nested-category chains are a later step.
     */
    public function forTaxonomy(string $slug): string
    {
        return '/'.trim($slug, '/');
    }

    /** The `/primary-category` prefix (localized), or '' when the post carries no category. */
    private function categoryPrefix(Entry $entry, string $locale): string
    {
        $category = $entry->taxonomies()->first();

        if ($category === null) {
            return '';
        }

        $translation = $category->translationFor($locale);

        return $translation !== null ? '/'.trim($translation->slug, '/') : '';
    }

    /**
     * The full ancestor chain of the post's primary category as localized slugs (root first), e.g.
     * `/noi-that/van-phong`. Empty when the post has no category. A visited-set guards a parent cycle.
     */
    private function categoryPath(Entry $entry, string $locale): string
    {
        $node = $entry->taxonomies()->first();
        $segments = [];
        $seen = [];

        while ($node instanceof Taxonomy && ! isset($seen[$node->id])) {
            $seen[$node->id] = true;
            $translation = $node->translationFor($locale);

            if ($translation !== null) {
                $slug = trim((string) $translation->slug, '/');

                if ($slug !== '') {
                    array_unshift($segments, $slug);
                }
            }

            $node = $node->parent_id !== null
                ? Taxonomy::query()->whereKey($node->parent_id)->first()
                : null;
        }

        return $segments === [] ? '' : '/'.implode('/', $segments);
    }

    /** The `/base` prefix for the `base` preset, or '' when the section has no base configured. */
    private function basePrefix(string $section): string
    {
        $base = $this->permalinks->base($section);

        return $base !== null ? '/'.$base : '';
    }
}
