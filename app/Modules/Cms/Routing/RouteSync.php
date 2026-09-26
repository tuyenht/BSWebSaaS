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
 * composed by PathBuilder (page = flat, post = category-prefixed, taxonomy = flat). When the
 * path of an entry, product or category changes it moves the route and records a 301 from the old
 * path ("lịch sử 301"); it also repoints any redirect that pointed AT the old path (so repeated
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
            $this->recordMove($locale, $route->path, $path, ['entry_id' => $entry->id]);
            $route->update(['path' => $path]);
        }

        return $path;
    }

    /**
     * Upsert the route for a product+locale at its composed path; returns that path. Mirrors sync():
     * on a slug/category change it moves the route and records a 301 from the old path. The path is
     * composed from PRIMITIVES (the product's primary category + slug) — Cms routing never references
     * the Catalog Product model, so the module graph stays acyclic. Product redirects carry no target
     * attribution (the redirects table has no product_id column yet); they still serve from → to.
     */
    public function syncProduct(int $productId, ?Taxonomy $primaryCategory, string $locale, string $slug): string
    {
        $path = $this->paths->forProduct($primaryCategory, $slug, $locale);

        $this->assertPathAvailable($locale, $path, 'product_id', $productId);

        $route = SiteRoute::query()
            ->where('product_id', $productId)
            ->where('locale', $locale)
            ->first();

        if ($route === null) {
            SiteRoute::query()->create(['locale' => $locale, 'path' => $path, 'product_id' => $productId]);

            return $path;
        }

        if ($route->path !== $path) {
            $this->recordMove($locale, $route->path, $path);
            $route->update(['path' => $path]);
        }

        return $path;
    }

    /**
     * Re-sync the PUBLISHED descendants of a renamed ancestor page so their nested paths pick up
     * the new prefix — each records its own 301. The page tree is small per site; drafts are
     * skipped (they carry no route until published). A visited-set guards against a parent cycle.
     */
    public function syncDescendants(Entry $ancestor, string $locale): void
    {
        $frontier = [$ancestor->id];
        $seen = [$ancestor->id => true];

        while ($frontier !== []) {
            $children = Entry::query()->whereIn('parent_id', $frontier)->get();
            $frontier = [];

            foreach ($children as $child) {
                if (isset($seen[$child->id])) {
                    continue;
                }
                $seen[$child->id] = true;
                $frontier[] = $child->id;

                if ($child->status === 'published') {
                    $translation = $child->translationFor($locale);
                    if ($translation !== null) {
                        $this->sync($child, $locale, $translation->slug);
                    }
                }
            }
        }
    }

    /**
     * Upsert the route for a taxonomy+locale at its flat path (/slug); returns that path. Mirrors
     * sync(): on a slug change it moves the route and records a 301 from the old path. It guards
     * against another target already owning the path (A2) and stays idempotent for re-provisioning.
     * Category redirects carry no target attribution (no taxonomy column on redirects), like products.
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
            $this->recordMove($locale, $route->path, $path);
            $route->update(['path' => $path]);
        }

        return $path;
    }

    /**
     * 301 a path whose target is gone (a deleted category) to where its visitors should land now,
     * with the same chain collapsing and loop guard as a move. The caller removes the old route.
     */
    public function retire(string $locale, string $from, string $to): void
    {
        if ($from !== $to) {
            $this->recordMove($locale, $from, $to);
        }
    }

    /**
     * Record that $from now lives at $to: anything that pointed AT $from is repointed (repeated
     * renames stay one hop, not a chain), $from → $to is upserted (idempotent on from_path), and a
     * redirect FROM $to is dropped — $to is live content now, and an A→B→A rename must not loop.
     *
     * @param  array{entry_id?: int}  $attribution  the entry a redirect leads to, when it is one
     */
    private function recordMove(string $locale, string $from, string $to, array $attribution = []): void
    {
        Redirect::query()
            ->where('locale', $locale)
            ->where('to_path', $from)
            ->update(['to_path' => $to, ...$attribution]);

        Redirect::query()->updateOrCreate(
            ['locale' => $locale, 'from_path' => $from],
            ['to_path' => $to, 'status_code' => 301, ...$attribution],
        );

        Redirect::query()->where('locale', $locale)->where('from_path', $to)->delete();
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
