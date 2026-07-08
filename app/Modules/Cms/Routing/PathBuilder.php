<?php

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Entry;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\SiteLocale;

/**
 * Composes an entry's canonical URL path (design.md §213: "path = danh mục + slug").
 * A `page` is flat (`/slug`, with the home slug → `/`); a `post` (or other collection
 * type) is prefixed by its primary category's localized slug (`/tin-tuc/slug`). The
 * prefix is built from the per-locale taxonomy slug — NOT the ltree `path` (which is
 * structural/query-only) — so localized URLs stay correct. Nested-category chains are
 * a later step; the slice uses flat (one-level) categories.
 */
final readonly class PathBuilder
{
    /** Conventional home slug → site root (matches ProvisionSite + seedHomepage). */
    public const string HOME_SLUG = 'home';

    public function __construct(
        private SiteLocale $siteLocale,
        private PermalinkConfig $permalinks,
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

        // Collection content (post): prefix with the primary category's localized slug.
        $prefix = $this->categoryPrefix($entry, $locale);

        return $prefix.'/'.$slug;
    }

    /**
     * A page's path. Flat `/slug` (home → `/`) by default; the site's `pages` permalink pattern
     * decides — only `nested` prefixes the page with its parent page's path (`/dich-vu/thiet-ke-web`).
     * Under the default flat pattern a `parent_id` is admin tree-grouping only and never nests the URL.
     * When nested, the chain is composed from ancestor SLUGS — not the stored route — so it is correct
     * no matter which ancestors are published yet, and a visited-set guards against a parent cycle.
     *
     * @param  array<int, true>  $seen
     */
    private function pagePath(Entry $entry, string $locale, string $slug, array $seen = []): string
    {
        if ($slug === '' || $slug === self::HOME_SLUG) {
            return '/';
        }

        $seen[$entry->id] = true;
        $parent = $this->permalinks->pagesNested()
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

    private function categoryPrefix(Entry $entry, string $locale): string
    {
        $category = $entry->taxonomies()->first();

        if ($category === null) {
            return '';
        }

        $translation = $category->translationFor($locale);

        return $translation !== null ? '/'.trim($translation->slug, '/') : '';
    }
}
