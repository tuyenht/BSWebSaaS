<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\PermalinkConfig;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What has to follow when categories change, for Cms's own posts and every kind a module registers
 * in CategoryDependents (Catalog's products):
 *  - relink(): after a rename or a move, re-sync the public paths of the items whose PRIMARY category
 *    (pivot position 0) is in the changed branch — RouteSync moves each route and leaves a 301;
 *  - refile(): before categories are deleted, move their items onto another category (which takes
 *    the deleted one's rank) or detach them, renumber each item's categories 0..n-1 so position 0 is
 *    a primary again — facet terms from FACET_POSITION_BASE up are never touched — then re-sync.
 * Paths are re-synced only for kinds whose permalink preset can carry the change (follows()): on a
 * site where everything is flat, a category change touches no item route.
 *
 * Runs inside the caller's transaction and tenant context; the pivot statements name `site_id`
 * explicitly (RLS is the net underneath). Items go 500 at a time by keyset, synchronously: moving
 * very large branches to a queue waits until a real site needs it (measure-first, ADR-18) — the
 * item-id lists the dependents take are what such a job would carry.
 */
final readonly class CategoryRelinker
{
    private const int CHUNK = 500;

    /** Presets whose path carries the primary category's slug. */
    private const array CATEGORY_PRESETS = ['category', 'category_path'];

    public function __construct(
        private CategoryDependents $dependents,
        private PermalinkConfig $permalinks,
        private SectionResolver $sections,
        private RouteSync $routes,
        private CurrentSite $site,
    ) {}

    /**
     * Re-sync the items filed primarily under these categories. Returns the routes moved.
     *
     * @param  list<int>  $taxonomyIds  the changed categories and everything under them
     *
     * @throws RouteConflictException an item's new path already belongs to something else
     */
    public function relink(array $taxonomyIds, CategoryChange $change): int
    {
        $moved = 0;
        if ($taxonomyIds === []) {
            return $moved;
        }

        foreach ($this->kinds() as $kind) {
            if (! $this->follows($kind['section'], $change)) {
                continue;
            }

            DB::table($kind['pivot'])
                ->select(['id', $kind['column']])
                ->where('site_id', $this->site->id())
                ->whereIn('taxonomy_id', $taxonomyIds)
                ->where('position', 0)
                ->chunkById(self::CHUNK, function (Collection $rows) use ($kind, &$moved): void {
                    $moved += ($kind['resync'])(self::ids($rows->pluck($kind['column'])));
                });
        }

        return $moved;
    }

    /**
     * Take every item off the $deleted categories — onto $targetId, or nowhere (null) — then re-sync
     * the paths of the items it touched.
     *
     * @param  list<int>  $deleted
     * @return array{items: int, routes: int} items re-filed, routes moved
     *
     * @throws RouteConflictException an item's new path already belongs to something else
     */
    public function refile(array $deleted, ?int $targetId): array
    {
        $items = 0;
        $routes = 0;
        if ($deleted === []) {
            return ['items' => $items, 'routes' => $routes];
        }

        foreach ($this->kinds() as $kind) {
            $follows = $this->follows($kind['section'], CategoryChange::Refiled);
            $after = 0;

            do {
                $chunk = self::ids(DB::table($kind['pivot'])
                    ->where('site_id', $this->site->id())
                    ->whereIn('taxonomy_id', $deleted)
                    ->where($kind['column'], '>', $after)
                    ->distinct()
                    ->orderBy($kind['column'])
                    ->limit(self::CHUNK)
                    ->pluck($kind['column']));

                if ($chunk === []) {
                    break;
                }
                $after = max($chunk);

                $this->rewrite($kind['pivot'], $kind['column'], $deleted, $targetId, $chunk);
                $items += count($chunk);

                if ($follows) {
                    $routes += ($kind['resync'])($chunk);
                }
            } while (count($chunk) === self::CHUNK);
        }

        return ['items' => $items, 'routes' => $routes];
    }

    /**
     * Whether any entry or registered item is filed under these categories (as a category or a
     * facet term).
     *
     * @param  list<int>  $taxonomyIds
     */
    public function hasItems(array $taxonomyIds): bool
    {
        foreach ($this->kinds() as $kind) {
            if (DB::table($kind['pivot'])->where('site_id', $this->site->id())->whereIn('taxonomy_id', $taxonomyIds)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any post has one of these categories as its PRIMARY category (pivot position 0) — the
     * category that decides the post's section, and so its permissions and approval workflow.
     *
     * @param  list<int>  $taxonomyIds
     */
    public function hasPrimaryPosts(array $taxonomyIds): bool
    {
        return $taxonomyIds !== [] && DB::table('entry_taxonomies')
            ->where('site_id', $this->site->id())
            ->whereIn('taxonomy_id', $taxonomyIds)
            ->where('position', 0)
            ->exists();
    }

    /**
     * Cms's own entries, then every registered kind. A null section means posts: their preset
     * depends on the section their primary category puts them in.
     *
     * @return list<array{pivot: string, column: string, section: string|null, resync: Closure(list<int>): int}>
     */
    private function kinds(): array
    {
        return [
            ['pivot' => 'entry_taxonomies', 'column' => 'entry_id', 'section' => null, 'resync' => $this->resyncEntries(...)],
            ...array_values($this->dependents->all()),
        ];
    }

    /** Whether a kind's public paths can change with this kind of category change. */
    private function follows(?string $section, CategoryChange $change): bool
    {
        if ($section === null) {
            // A post's preset comes from the section its primary category resolves to, and a move or
            // a re-file can switch that section — then any non-flat preset may change its path.
            $patterns = array_map(
                fn (array $postSection): string => $this->permalinks->pattern($postSection['key'], $postSection['type']),
                $this->sections->postSections(),
            );

            return $change === CategoryChange::Renamed
                ? array_intersect($patterns, self::CATEGORY_PRESETS) !== []
                : array_diff($patterns, [PermalinkConfig::DEFAULT_PATTERN]) !== [];
        }

        $pattern = $this->permalinks->pattern($section, $section);

        return $change === CategoryChange::Moved
            ? $pattern === 'category_path'
            : in_array($pattern, self::CATEGORY_PRESETS, true);
    }

    /**
     * Re-sync the routes of these entries' published posts, in every language they have one in.
     * Returns the routes moved.
     *
     * @param  list<int>  $ids
     */
    private function resyncEntries(array $ids): int
    {
        $moved = 0;
        $posts = Entry::query()
            ->whereKey($ids)
            ->where('type', 'post')
            ->where('status', 'published')
            ->with([
                ...PathBuilder::primaryCategoryRelations(),
                'translations:id,entry_id,locale,slug',
                'routes:id,entry_id,locale,path',
            ])
            ->get(['id', 'type']);

        foreach ($posts as $post) {
            foreach ($post->routes as $route) {
                $translation = $post->translationFor($route->locale);
                if ($translation !== null && $this->routes->sync($post, $route->locale, $translation->slug) !== $route->path) {
                    $moved++;
                }
            }
        }

        return $moved;
    }

    /**
     * Re-file one chunk of items in a pivot, set-based.
     *
     * @param  list<int>  $deleted
     * @param  list<int>  $items
     */
    private function rewrite(string $pivot, string $column, array $deleted, ?int $targetId, array $items): void
    {
        $site = $this->site->id();
        $deletedIds = self::pgArray($deleted);
        $itemIds = self::pgArray($items);

        if ($targetId === null) {
            DB::table($pivot)->where('site_id', $site)->whereIn('taxonomy_id', $deleted)->whereIn($column, $items)->delete();
        } else {
            // An item already under the target: the target takes the best rank the item gave any
            // deleted category, when that beats its own…
            DB::update(<<<SQL
                UPDATE {$pivot} AS p SET position = d.best
                FROM (
                    SELECT {$column} AS item, MIN(position) AS best FROM {$pivot}
                    WHERE site_id = ? AND taxonomy_id = ANY(CAST(? AS bigint[])) AND {$column} = ANY(CAST(? AS bigint[]))
                    GROUP BY {$column}
                ) AS d
                WHERE p.site_id = ? AND p.taxonomy_id = ? AND p.{$column} = d.item AND d.best < p.position
                SQL, [$site, $deletedIds, $itemIds, $site, $targetId]);

            // …and the deleted categories go.
            DB::delete(<<<SQL
                DELETE FROM {$pivot} AS p
                WHERE p.site_id = ? AND p.taxonomy_id = ANY(CAST(? AS bigint[])) AND p.{$column} = ANY(CAST(? AS bigint[]))
                  AND EXISTS (SELECT 1 FROM {$pivot} AS t WHERE t.site_id = p.site_id AND t.{$column} = p.{$column} AND t.taxonomy_id = ?)
                SQL, [$site, $deletedIds, $itemIds, $targetId]);

            // Otherwise an item under several deleted categories keeps only its best-ranked one…
            DB::delete(<<<SQL
                DELETE FROM {$pivot} AS p USING {$pivot} AS q
                WHERE p.site_id = ? AND q.site_id = p.site_id AND q.{$column} = p.{$column}
                  AND p.taxonomy_id = ANY(CAST(? AS bigint[])) AND q.taxonomy_id = ANY(CAST(? AS bigint[]))
                  AND p.{$column} = ANY(CAST(? AS bigint[]))
                  AND (q.position, q.id) < (p.position, p.id)
                SQL, [$site, $deletedIds, $deletedIds, $itemIds]);

            // …which moves onto the target in place, keeping its rank.
            DB::table($pivot)->where('site_id', $site)->whereIn('taxonomy_id', $deleted)->whereIn($column, $items)->update(['taxonomy_id' => $targetId]);
        }

        // Categories rank 0..n-1 with 0 the primary: close the gaps a removed category left.
        DB::update(<<<SQL
            UPDATE {$pivot} AS p SET position = r.new_position
            FROM (
                SELECT id, row_number() OVER (PARTITION BY {$column} ORDER BY position, id) - 1 AS new_position
                FROM {$pivot}
                WHERE site_id = ? AND {$column} = ANY(CAST(? AS bigint[])) AND position < ?
            ) AS r
            WHERE p.site_id = ? AND p.id = r.id AND p.position <> r.new_position
            SQL, [$site, $itemIds, Entry::FACET_POSITION_BASE, $site]);
    }

    /**
     * @param  Collection<array-key, mixed>  $values
     * @return list<int>
     */
    private static function ids(Collection $values): array
    {
        return array_values(array_unique(array_map(static fn (mixed $value): int => Cast::int($value), $values->all())));
    }

    /**
     * A Postgres array literal of ids — ints only, so nothing needs quoting.
     *
     * @param  list<int>  $ids
     */
    private static function pgArray(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }
}
