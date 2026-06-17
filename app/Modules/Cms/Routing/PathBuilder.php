<?php

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Entry;

/**
 * Composes an entry's canonical URL path (design.md §213: "path = danh mục + slug").
 * A `page` is flat (`/slug`, with the home slug → `/`); a `post` (or other collection
 * type) is prefixed by its primary category's localized slug (`/tin-tuc/slug`). The
 * prefix is built from the per-locale taxonomy slug — NOT the ltree `path` (which is
 * structural/query-only) — so localized URLs stay correct. Nested-category chains are
 * a later step; the slice uses flat (one-level) categories.
 */
final class PathBuilder
{
    /** Conventional home slug → site root (matches ProvisionSite + seedHomepage). */
    public const HOME_SLUG = 'home';

    public function forEntry(Entry $entry, string $locale, string $slug): string
    {
        $slug = trim($slug, '/');

        if ($entry->type === 'page') {
            return ($slug === '' || $slug === self::HOME_SLUG) ? '/' : '/'.$slug;
        }

        // Collection content (post): prefix with the primary category's localized slug.
        $prefix = $this->categoryPrefix($entry, $locale);

        return $prefix.'/'.$slug;
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
