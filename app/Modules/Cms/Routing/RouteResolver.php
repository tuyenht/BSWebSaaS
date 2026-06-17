<?php

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;

/**
 * Resolves a request (locale, path) to a publishable target within the CURRENT site
 * (all queries auto-scoped by BelongsToSite + RLS): a published entry (page/post) OR a
 * category (taxonomy) list page. Returns null when there is no route or the target is
 * not publishable — the caller then looks for a 301 (slug history) and otherwise 404s.
 * Entry visibility: entry.status=published AND translation.status=published.
 */
final class RouteResolver
{
    public function resolve(string $locale, string $path): ResolvedContent|ResolvedCategory|null
    {
        $route = SiteRoute::query()
            ->where('locale', $locale)
            ->where('path', $path)
            ->first();

        if ($route === null) {
            return null;
        }

        if ($route->entry_id !== null) {
            return $this->resolveEntry($route->entry_id, $locale);
        }

        if ($route->taxonomy_id !== null) {
            return $this->resolveCategory($route->taxonomy_id, $locale);
        }

        return null; // product routes are not wired yet
    }

    /**
     * A 301/302 mapping for a path that no longer resolves (the slug moved). Checked
     * only after resolve() misses, so live content always wins over its own history.
     */
    public function redirect(string $locale, string $path): ?Redirect
    {
        return Redirect::query()
            ->where('locale', $locale)
            ->where('from_path', $path)
            ->first();
    }

    private function resolveEntry(int $entryId, string $locale): ?ResolvedContent
    {
        // Eager-load every PUBLISHED translation (not just the current locale) so the storefront
        // can build hreflang alternates without an extra query — net-zero vs the single-locale
        // load it replaces.
        $entry = Entry::query()
            ->whereKey($entryId)
            ->where('status', 'published')
            ->with('translations')
            ->first();

        if ($entry === null) {
            return null;
        }

        $translation = $entry->translations
            ->first(fn (EntryTranslation $candidate) => $candidate->locale === $locale && $candidate->status === 'published');

        if ($translation === null) {
            return null;
        }

        return new ResolvedContent($entry, $translation);
    }

    private function resolveCategory(int $taxonomyId, string $locale): ?ResolvedCategory
    {
        // Eager-load all translations (for hreflang alternates) — net-zero vs the lazy
        // translationFor() it replaces.
        $taxonomy = Taxonomy::query()->whereKey($taxonomyId)->with('translations')->first();

        if ($taxonomy === null) {
            return null;
        }

        $translation = $taxonomy->translationFor($locale);

        if ($translation === null) {
            return null;
        }

        return new ResolvedCategory($taxonomy, $translation);
    }
}
