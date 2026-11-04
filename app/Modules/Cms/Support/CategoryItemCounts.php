<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Routing\CategoryDependents;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The one writer of `taxonomies.items_count`: how many items have the category as their MAIN one
 * (pivot position 0, HasCategoryTaxonomies) and are not in the trash. Items are Cms's posts
 * (`entries.type = 'post'`, any status — what the post list filtered by the category shows under
 * "Tất cả"; pages are not counted) and every kind a module files under categories through
 * CategoryDependents (Catalog's products, any status). A category further down the item's list,
 * a facet term (sector, tag…) and the items of sub-categories are not counted.
 *
 * - Every change is relative and atomic in SQL (`items_count + n`, never read-then-write), in the
 *   caller's transaction and tenant context, with the site named explicitly.
 * - Several categories in one change are updated in id order, so two saves touching the same
 *   categories queue behind each other instead of deadlocking. A plain UPDATE of items_count (no
 *   key column) takes FOR NO KEY UPDATE on the row, which does not conflict with the FOR KEY
 *   SHARE that every insert into a pivot or `routes` takes on its category: filing an item never
 *   waits for a counter change.
 * - A decrement never takes a counter below 0. It is clamped (GREATEST(…, 0)) instead of left to
 *   the CHECK (items_count >= 0), which would turn a counter that has already drifted (a write
 *   path that missed it) into a failed save for the person; `cms:reconcile-category-counts`
 *   corrects the drift every night and logs it.
 * - batch() gathers the changes of a bulk action and writes ONE update per category at its end;
 *   a nested batch around each row drops the changes of a row that rolled back.
 *
 * Holds the open batches of one request or job: bound scoped, so nothing outlives an Octane
 * operation.
 */
final class CategoryItemCounts
{
    /** @var list<array<int, int>> the open batches, innermost last: taxonomy id → change */
    private array $batches = [];

    public function __construct(
        private readonly CategoryDependents $dependents,
        private readonly CurrentSite $site,
    ) {}

    /** An item that counts moved from one main category to another (null: none). */
    public function moved(?int $from, ?int $to): void
    {
        if ($from === $to) {
            return;
        }

        $changes = [];
        if ($from !== null) {
            $changes[$from] = -1;
        }
        if ($to !== null) {
            $changes[$to] = 1;
        }
        $this->adjust($changes);
    }

    /**
     * Add each change to its category's count — now, or at the end of the open batch.
     *
     * @param  array<int, int>  $changes  taxonomy id → items added (negative: removed)
     */
    public function adjust(array $changes): void
    {
        $changes = array_filter($changes, static fn (int $change): bool => $change !== 0);
        if ($changes === []) {
            return;
        }

        $open = array_key_last($this->batches);
        if ($open !== null) {
            foreach ($changes as $id => $change) {
                $this->batches[$open][$id] = ($this->batches[$open][$id] ?? 0) + $change;
            }

            return;
        }

        ksort($changes);   // id order: see the class comment
        $site = $this->site->id();
        foreach ($changes as $id => $change) {
            DB::update('UPDATE taxonomies SET items_count = GREATEST(items_count + ?, 0) WHERE site_id = ? AND id = ?', [$change, $site, $id]);
        }
    }

    /**
     * Run $work, holding back every count change it makes until it is done; then they are written
     * (one update per category) — or handed to the enclosing batch. If $work throws, its changes
     * are dropped with it: wrap each row of a bulk action, whose savepoint rolls back on failure.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public function batch(Closure $work): mixed
    {
        $this->batches[] = [];
        try {
            $result = $work();
        } catch (Throwable $e) {
            array_pop($this->batches);

            throw $e;
        }

        $this->adjust(array_pop($this->batches) ?? []);

        return $result;
    }

    /**
     * How many of these items, filed through $pivot, count toward each category: their main
     * category, when they are live. Read before and after a set-based re-filing (settle()).
     *
     * @param  list<int>  $itemIds
     * @return array<int, int> taxonomy id → items
     */
    public function primaries(string $pivot, array $itemIds): array
    {
        $kinds = array_values(array_filter($this->kinds(), static fn (array $kind): bool => $kind['pivot'] === $pivot));
        if ($kinds === []) {
            throw new InvalidArgumentException("No item kind is filed under categories through '{$pivot}'.");
        }

        return $itemIds === [] ? [] : $this->tally($kinds, null, $itemIds);
    }

    /**
     * Apply the difference between two primaries() readings of the same items.
     *
     * @param  array<int, int>  $before
     * @param  array<int, int>  $after
     */
    public function settle(array $before, array $after): void
    {
        $changes = $after;
        foreach ($before as $id => $count) {
            $changes[$id] = ($changes[$id] ?? 0) - $count;
        }
        $this->adjust($changes);
    }

    /**
     * The true count of each of these categories, from the pivots (one statement): what
     * items_count should hold. For the reconcile.
     *
     * @param  list<int>  $taxonomyIds
     * @return array<int, int> taxonomy id → items (categories with none are left out)
     */
    public function actual(array $taxonomyIds): array
    {
        return $taxonomyIds === [] ? [] : $this->tally($this->kinds(), $taxonomyIds, null);
    }

    /**
     * Overwrite these categories' counts with the true numbers (the reconcile, after locking the
     * rows and counting). One statement.
     *
     * @param  array<int, int>  $counts  taxonomy id → items
     */
    public function correct(array $counts): void
    {
        if ($counts === []) {
            return;
        }

        $values = implode(', ', array_fill(0, count($counts), '(?::bigint, ?::integer)'));
        $bindings = [];
        foreach ($counts as $id => $count) {
            $bindings[] = $id;
            $bindings[] = max($count, 0);
        }
        $bindings[] = $this->site->id();

        DB::update("UPDATE taxonomies AS t SET items_count = v.n FROM (VALUES {$values}) AS v(id, n) WHERE t.id = v.id AND t.site_id = ?", $bindings);
    }

    /**
     * Count the live items by main category, for some categories or some items.
     *
     * @param  list<array{pivot: string, column: string, items: string, only: string}>  $kinds
     * @param  list<int>|null  $taxonomyIds
     * @param  list<int>|null  $itemIds
     * @return array<int, int>
     */
    private function tally(array $kinds, ?array $taxonomyIds, ?array $itemIds): array
    {
        $site = $this->site->id();
        $parts = [];
        $bindings = [];
        foreach ($kinds as $kind) {
            // Table and column names are plain identifiers (CategoryDependents checks them).
            $among = $taxonomyIds !== null ? 'p.taxonomy_id' : "p.{$kind['column']}";
            $parts[] = <<<SQL
                SELECT p.taxonomy_id, COUNT(*) AS n
                FROM {$kind['pivot']} AS p
                JOIN {$kind['items']} AS i ON i.site_id = p.site_id AND i.id = p.{$kind['column']}
                WHERE p.site_id = ? AND p.position = 0 AND {$among} = ANY(CAST(? AS bigint[]))
                  AND i.deleted_at IS NULL{$kind['only']}
                GROUP BY p.taxonomy_id
                SQL;
            $bindings[] = $site;
            $bindings[] = '{'.implode(',', $taxonomyIds ?? $itemIds ?? []).'}';
        }

        $counts = [];
        foreach (DB::select('SELECT taxonomy_id, SUM(n) AS n FROM ('.implode(' UNION ALL ', $parts).') AS c GROUP BY taxonomy_id', $bindings) as $row) {
            $row = (array) $row;
            $counts[Cast::int($row['taxonomy_id'] ?? null)] = Cast::int($row['n'] ?? null);
        }

        return $counts;
    }

    /**
     * Cms's posts, then every kind a module registered.
     *
     * @return list<array{pivot: string, column: string, items: string, only: string}>
     */
    private function kinds(): array
    {
        // The type is a literal, so the partial post indexes match a generic plan too.
        $kinds = [['pivot' => 'entry_taxonomies', 'column' => 'entry_id', 'items' => 'entries', 'only' => " AND i.type = 'post'"]];
        foreach ($this->dependents->all() as $kind) {
            $kinds[] = ['pivot' => $kind['pivot'], 'column' => $kind['column'], 'items' => $kind['items'], 'only' => ''];
        }

        return $kinds;
    }
}
