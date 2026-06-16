<?php

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;

/**
 * Keeps an entry's canonical route in sync with its slug (design.md §213). On a slug
 * change it moves the route and records a 301 from the old path ("lịch sử 301"), and
 * drops any stale redirect that pointed AT the new path so an A→B→A rename cannot
 * create a redirect loop. Runs inside the tenant context (BelongsToSite + RLS scope
 * every query/insert to the current site).
 */
final class RouteSync
{
    /** The conventional home slug maps to the site root (matches ProvisionSite + seedHomepage). */
    public const HOME_SLUG = 'home';

    /** Upsert the route for an entry+locale at the slug-derived path; returns that path. */
    public function sync(Entry $entry, string $locale, string $slug): string
    {
        $path = $this->pathForSlug($slug);

        $route = SiteRoute::query()
            ->where('entry_id', $entry->id)
            ->where('locale', $locale)
            ->first();

        if ($route === null) {
            SiteRoute::query()->create(['locale' => $locale, 'path' => $path, 'entry_id' => $entry->id]);

            return $path;
        }

        if ($route->path !== $path) {
            Redirect::query()->updateOrCreate(
                ['locale' => $locale, 'from_path' => $route->path],
                ['to_path' => $path, 'status_code' => 301, 'entry_id' => $entry->id],
            );

            // The new path is now live content — retire any redirect that pointed at it.
            Redirect::query()->where('locale', $locale)->where('from_path', $path)->delete();

            $route->update(['path' => $path]);
        }

        return $path;
    }

    public function pathForSlug(string $slug): string
    {
        $slug = trim($slug, '/');

        return ($slug === '' || $slug === self::HOME_SLUG) ? '/' : '/'.$slug;
    }
}
