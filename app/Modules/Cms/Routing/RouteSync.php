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
 * this covers entry-vs-entry AND entry-vs-taxonomy (A2), and a path the platform keeps for a page of
 * its own (PathBuilder::RESERVED_PATHS: the site search) is refused the same way, unless the target already stands
 * there (content from before the address was reserved keeps it). Runs inside the tenant context
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
            $this->recordMove($locale, $route->path, $path, self::leadsTo(entry: $entry->id));
            $route->update(['path' => $path]);
        }

        return $path;
    }

    /**
     * Upsert the route for a product+locale at its composed path; returns that path. Mirrors sync():
     * on a slug/category change it moves the route and records a 301 from the old path. The path is
     * composed from PRIMITIVES (the product's primary category + slug) — Cms routing never references
     * the Catalog Product model, so the module graph stays acyclic. Its redirects name the product
     * (product_id), so the «Chuyển hướng» screen can say what they came from.
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
            $this->recordMove($locale, $route->path, $path, self::leadsTo(product: $productId));
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
     * A page going live (again): its published sub-pages follow the address it stands at in each
     * language, each move with its 301. While the page was offline its own address may have moved (a
     * pending change folded into it as it left the website, EntryDraftFolder) and the sub-pages kept
     * theirs. A language whose page address is not where its slug puts it yet is left: its sub-pages
     * move when that language goes live. Called by every road that publishes, after the page's own
     * route sync, under the page's row lock; a sub-page's address another item holds throws, so the
     * publication rolls back as for the page's own address.
     *
     * @throws RouteConflictException
     */
    public function syncSubPagesOf(Entry $page): void
    {
        if ($page->type !== 'page' || ! Entry::query()->where('parent_id', $page->id)->exists()) {
            return;
        }
        $routed = SiteRoute::query()->where('entry_id', $page->id)->pluck('path', 'locale')->all();
        if ($routed === []) {
            return;
        }
        foreach ($page->translations()->whereIn('locale', array_keys($routed))->orderBy('locale')->get(['id', 'entry_id', 'locale', 'slug']) as $translation) {
            $locale = $translation->locale;
            if ($routed[$locale] === $this->paths->forEntry($page, $locale, (string) $translation->slug)) {
                $this->syncDescendants($page, $locale);
            }
        }
    }

    /**
     * Upsert the route for a taxonomy+locale at its flat path (/slug); returns that path. Mirrors
     * sync(): on a slug change it moves the route and records a 301 from the old path. It guards
     * against another target already owning the path (A2) and stays idempotent for re-provisioning.
     * Its redirects name the category (taxonomy_id), like products name theirs.
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
            $this->recordMove($locale, $route->path, $path, self::leadsTo(taxonomy: $taxonomy->id));
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
     * renames stay one hop, not a chain; rows in the trash too, so a restore lands on the live
     * address), $from → $to is upserted as a system row (idempotent on the live from_path), and any
     * redirect FROM $to is removed for good — $to is live content now, and an A→B→A rename must not
     * loop, nor come back from the trash. A member's redirect to another website is left as it is on
     * both ends (Redirect::leadsAway): only the member changes one, with an audit row; it never points
     * at $from, so the first step cannot reach it either.
     *
     * @param  array{entry_id: int|null, product_id: int|null, taxonomy_id: int|null}|array{}  $target  what it leads to (leadsTo()), when known
     */
    private function recordMove(string $locale, string $from, string $to, array $target = []): void
    {
        Redirect::query()->withTrashed()
            ->where('locale', $locale)
            ->where('to_path', $from)
            ->update(['to_path' => $to, ...$target]);

        Redirect::writeSystemRow($locale, $from, ['to_path' => $to, 'status_code' => 301, 'source' => Redirect::SOURCE_AUTO, ...$target]);

        Redirect::query()->withTrashed()->where('locale', $locale)->where('from_path', $to)
            ->whereNot('to_path', 'like', Redirect::EXTERNAL_PREFIX.'%')->forceDelete();
    }

    /**
     * The content a redirect leads to: one of the three set, the other two cleared, so a row that
     * changes hands never names two targets.
     *
     * @return array{entry_id: int|null, product_id: int|null, taxonomy_id: int|null}
     */
    private static function leadsTo(?int $entry = null, ?int $product = null, ?int $taxonomy = null): array
    {
        return ['entry_id' => $entry, 'product_id' => $product, 'taxonomy_id' => $taxonomy];
    }

    /**
     * The route that already holds $path in $locale for a DIFFERENT target than the caller's own
     * (null when the path is free for it). A path is owned by exactly one target: a different row
     * in the same column, or (A2) the other kind of target at the same path. The other target type
     * leaves THIS column NULL, which a bare `!=` would silently miss (`NULL != id` is unknown), so
     * the NULL is matched explicitly. The one rule behind every "is this address free" answer.
     */
    public function foreignRoute(string $locale, string $path, string $ownColumn, int $ownId): ?SiteRoute
    {
        return SiteRoute::query()
            ->where('locale', $locale)
            ->where('path', $path)
            ->where(function (Builder $query) use ($ownColumn, $ownId): void {
                $query->whereNull($ownColumn)->orWhere($ownColumn, '!=', $ownId);
            })
            ->select(['id', 'taxonomy_id'])
            ->first();
    }

    /**
     * A reserved path (the site search) is refused only to a target that does not stand there yet: content
     * that took the address before it was reserved keeps it, so it can still be saved, published, moved
     * along its workflow or re-synced (one more read, only for a reserved path).
     *
     * @throws RouteConflictException the path belongs to a different target, or to a page of the platform (the site search)
     */
    private function assertPathAvailable(string $locale, string $path, string $ownColumn, int $ownId): void
    {
        if (PathBuilder::isReserved($path) && ! $this->standsAt($locale, $path, $ownColumn, $ownId)) {
            throw new RouteConflictException($path);
        }
        if ($this->foreignRoute($locale, $path, $ownColumn, $ownId) !== null) {
            throw new RouteConflictException($path);
        }
    }

    /** Whether the target's own route in $locale is already at $path. */
    private function standsAt(string $locale, string $path, string $ownColumn, int $ownId): bool
    {
        return SiteRoute::query()
            ->where('locale', $locale)
            ->where('path', $path)
            ->where($ownColumn, $ownId)
            ->exists();
    }
}
