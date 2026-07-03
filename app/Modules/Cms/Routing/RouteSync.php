<?php

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps a target's canonical route in sync with its slug (design.md §213). The path is
 * composed by PathBuilder (page = flat, post = category-prefixed, taxonomy = flat). On an
 * entry slug/category change it moves the route and records a 301 from the old path
 * ("lịch sử 301"); it also repoints any redirect that pointed AT the old path (so repeated
 * renames stay a single hop, not a chain) and drops a redirect FROM the new path (so an
 * A→B→A rename cannot loop). A path resolves to exactly one target — a clash with a
 * DIFFERENT target raises RouteConflictException (a friendly "slug taken", not a DB 500);
 * this covers entry-vs-entry AND entry-vs-taxonomy (A2). Runs inside the tenant context
 * (BelongsToSite + RLS scope every query to the site).
 */
final readonly class RouteSync
{
    public function __construct(private PathBuilder $paths) {}

    /** Upsert the route for an entry+locale at the composed path; returns that path. */
    public function sync(Entry $entry, string $locale, string $slug): string
    {
        $path = $this->paths->forEntry($entry, $locale, $slug);

        $this->assertPathAvailable($locale, $path, 'entry_id', $entry->id);

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

    /**
     * Upsert the route for a taxonomy+locale at its flat path (/slug); returns that path.
     * Categories aren't admin-renamed yet, so this carries no 301 history (that arrives
     * with the category editor); it still guards against an entry already owning the path
     * (A2) and stays idempotent for re-provisioning.
     */
    public function syncTaxonomy(Taxonomy $taxonomy, string $locale, string $slug): string
    {
        $path = $this->paths->forTaxonomy($slug);

        $this->assertPathAvailable($locale, $path, 'taxonomy_id', $taxonomy->id);

        $route = SiteRoute::query()
            ->where('taxonomy_id', $taxonomy->id)
            ->where('locale', $locale)
            ->first();

        if ($route === null) {
            SiteRoute::query()->create(['locale' => $locale, 'path' => $path, 'taxonomy_id' => $taxonomy->id]);

            return $path;
        }

        if ($route->path !== $path) {
            $route->update(['path' => $path]);
        }

        return $path;
    }

    /**
     * A path is owned by exactly one target; reject a clash with a DIFFERENT target — a
     * different row in the same column, or (A2) the other kind of target at the same path.
     * The other target type leaves THIS column NULL, which a bare `!=` would silently miss
     * (`NULL != id` is unknown), so the NULL is matched explicitly.
     */
    private function assertPathAvailable(string $locale, string $path, string $ownColumn, int $ownId): void
    {
        $taken = SiteRoute::query()
            ->where('locale', $locale)
            ->where('path', $path)
            ->where(function (Builder $query) use ($ownColumn, $ownId): void {
                $query->whereNull($ownColumn)->orWhere($ownColumn, '!=', $ownId);
            })
            ->exists();

        if ($taken) {
            throw new RouteConflictException($path);
        }
    }
}
