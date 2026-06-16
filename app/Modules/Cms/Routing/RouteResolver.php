<?php

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;

/**
 * Resolves a request (locale, path) to publishable content within the CURRENT site
 * (all queries auto-scoped by BelongsToSite + RLS). Returns null when there is no
 * route, the entry is not published, or the locale is not published — the caller
 * then looks for a 301 (slug history) and otherwise 404s. Visibility rule:
 * entry.status=published AND translation.status=published.
 */
final class RouteResolver
{
    public function resolve(string $locale, string $path): ?ResolvedContent
    {
        $route = SiteRoute::query()
            ->where('locale', $locale)
            ->where('path', $path)
            ->whereNotNull('entry_id')
            ->first();

        if ($route === null || $route->entry_id === null) {
            return null;
        }

        $entry = Entry::query()
            ->whereKey($route->entry_id)
            ->where('status', 'published')
            ->first();

        if ($entry === null) {
            return null;
        }

        $translation = $entry->translations()
            ->where('locale', $locale)
            ->where('status', 'published')
            ->first();

        if ($translation === null) {
            return null;
        }

        return new ResolvedContent($entry, $translation);
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
}
