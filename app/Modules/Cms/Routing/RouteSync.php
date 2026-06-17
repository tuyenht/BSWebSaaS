<?php

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;

/**
 * Keeps an entry's canonical route in sync with its slug (design.md §213). The path is
 * composed by PathBuilder (page = flat, post = category-prefixed). On a slug/category
 * change it moves the route and records a 301 from the old path ("lịch sử 301"); it
 * also repoints any redirect that pointed AT the old path (so repeated renames stay a
 * single hop, not a chain) and drops a redirect FROM the new path (so an A→B→A rename
 * cannot loop). A path resolves to exactly one entry — a collision with a different
 * entry's route raises RouteConflictException (a friendly "slug taken", not a DB 500).
 * Runs inside the tenant context (BelongsToSite + RLS scope every query to the site).
 */
final class RouteSync
{
    public function __construct(private readonly PathBuilder $paths) {}

    /** Upsert the route for an entry+locale at the composed path; returns that path. */
    public function sync(Entry $entry, string $locale, string $slug): string
    {
        $path = $this->paths->forEntry($entry, $locale, $slug);

        $this->assertNoCollision($entry, $locale, $path);

        $route = SiteRoute::query()
            ->where('entry_id', $entry->id)
            ->where('locale', $locale)
            ->first();

        if ($route === null) {
            SiteRoute::query()->create(['locale' => $locale, 'path' => $path, 'entry_id' => $entry->id]);

            return $path;
        }

        if ($route->path !== $path) {
            $oldPath = $route->path;

            // Collapse chains: anything that pointed at the old path now points at the new one.
            Redirect::query()
                ->where('locale', $locale)
                ->where('to_path', $oldPath)
                ->update(['to_path' => $path, 'entry_id' => $entry->id]);

            // Record old → new (idempotent on from_path).
            Redirect::query()->updateOrCreate(
                ['locale' => $locale, 'from_path' => $oldPath],
                ['to_path' => $path, 'status_code' => 301, 'entry_id' => $entry->id],
            );

            // The new path is now live content — retire any redirect FROM it (loop guard).
            Redirect::query()->where('locale', $locale)->where('from_path', $path)->delete();

            $route->update(['path' => $path]);
        }

        return $path;
    }

    /** A path is owned by exactly one entry; reject a clash with a different entry's route. */
    private function assertNoCollision(Entry $entry, string $locale, string $path): void
    {
        $taken = SiteRoute::query()
            ->where('locale', $locale)
            ->where('path', $path)
            ->where('entry_id', '!=', $entry->id)
            ->exists();

        if ($taken) {
            throw new RouteConflictException($path);
        }
    }
}
