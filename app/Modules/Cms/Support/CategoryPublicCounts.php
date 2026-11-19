<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\CategoryDependents;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one writer of a category's public totals, per language, on `taxonomy_translations`: how many
 * items a visitor sees on the category's page in that language — the number the storefront search
 * suggestion shows («Sản phẩm · 12 mục»). The admin total (`taxonomies.items_count`,
 * CategoryItemCounts) counts drafts too and is never shown to visitors.
 *
 * An item is PUBLIC in language L when it is not in the trash and:
 *  - a post (`entries.type = 'post'`, pages never count): `status = 'published'` and its translation
 *    in L is `published` (what the category page lists, StorefrontController::renderCategory);
 *  - a kind a module registered with a translations table (CategoryDependents: Catalog's products):
 *    `status = 'published'` and a translation in L (renderProductIndex).
 * Its FILINGS are the categories it is listed under: a post's main category (pivot position 0); a
 * product's every category (any position below the facet terms). Then:
 *  - `public_own_count(c, L)`: the public items filed under c — the default (titles) layout;
 *  - `public_tree_count(c, L)`: the public items filed under c or any category below it, EACH ITEM
 *    ONCE even when filed under several of them — the card layout. An item adds 1 to every category
 *    on the paths (ltree `taxonomies.path`, ancestors and self) of its filings, taken as a set.
 * A kind's catalog root (the category its content section names, `content_sections.taxonomy_id`:
 * Catalog's product list) lists every public item of the kind, filed or not, so each one adds 1 to
 * the root's tree total once, besides its filings. What this does not mirror (the page differs): a
 * category at or under the catalog root lists posts until the site has a published product, products
 * after; the counts follow the filings. A root named afresh is counted by the nightly reconcile.
 *
 * How a change is counted: the item's contribution (its rows of the two totals) is read before and
 * after the write, with the item's row locked first (FOR UPDATE, in the caller's transaction), and
 * the difference is added to the stored totals — relative and atomic in SQL
 * (`count + n`, never read-then-write), clamped at 0 like CategoryItemCounts (a drifted counter
 * never fails a save; the nightly `cms:reconcile-category-public-counts` corrects it and logs it).
 * Whatever the write (status, a translation, the filings, trash, restore, a permanent delete), the
 * same before/after reading gives the right change, in every language at once. A category moved under
 * another parent, or a category translation added, is counted again off the request once it commits
 * (RecountCategoryPublicCounts): a top category's total is its whole branch.
 *
 * - track() wraps an action that writes an item several times (a publish writes the entry and its
 *   translation): one reading before, one after. The model events of HasCategoryTaxonomies and
 *   CountsInCategoryPublicTotals call begin()/end() for every other write, and stay silent for an
 *   item a track() around them already counts.
 * - Each change updates its rows in (category, language) order, after the item rows it read, and a
 *   writer that also moves items_count (a re-filing, a trash) writes those first — so two such saves
 *   on the same categories queue. One transaction that makes several separate changes (a translation
 *   saved, then the filings) can still take rows in another order than a concurrent save: PostgreSQL
 *   then stops one of them as a deadlock, and that save fails and can be repeated.
 * - batch() gathers the changes of a bulk action (CategoryItemCounts::batch opens one around every
 *   bulk action) and writes ONE update per row at its end; a nested batch around each row drops the
 *   changes of a row that rolled back. Inside a batch, the items a trash, a restore or a status change
 *   takes off or puts on the website are read together when it closes (deferred()), so the reads do
 *   not grow with the number of rows.
 * - The site is the current one (CurrentSite), named in every statement; RLS is the net underneath.
 *   An item of another site is never found, so never counted; with no current site nothing is
 *   counted (the reconcile, which enters each site, would correct it).
 *
 * Holds the open batches and tracked items of one request or job: bound scoped, so nothing outlives
 * an Octane operation.
 */
final class CategoryPublicCounts
{
    /**
     * The open batches, innermost last: their changes ("taxonomy|locale" → [own, tree]) and the items
     * that went off ('out') or on ('in') the website in them, by pivot, each with the FIRST way it
     * went (what it added when the batch began follows from it), read once when the outermost closes.
     *
     * @var list<array{changes: array<string, array{0: int, 1: int}>, deferred: array<string, array<int, 'out'|'in'>>}>
     */
    private array $batches = [];

    /** @var array<string, true> "pivot:id" of the items a track() is counting now */
    private array $tracked = [];

    public function __construct(
        private readonly CategoryDependents $dependents,
        private readonly CurrentSite $site,
    ) {}

    /**
     * Run $work on these items (filed through $pivot) and add the change it made to their categories'
     * public totals. The items are locked first. Items another track() already counts are left to it.
     *
     * @template TResult
     *
     * @param  list<int>  $ids
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public function track(string $pivot, array $ids, Closure $work): mixed
    {
        $kind = $this->site->hasId() ? ($this->kinds()[$pivot] ?? null) : null;
        $ids = array_values(array_filter(array_unique($ids), fn (int $id): bool => ! isset($this->tracked["{$pivot}:{$id}"]) && ! $this->isDeferred($pivot, $id)));
        if ($kind === null || $ids === []) {
            return $work();
        }

        $ids = $this->lock($kind, $ids);
        if ($ids === []) {
            return $work();
        }

        $before = $this->contribution($kind, $ids);
        foreach ($ids as $id) {
            $this->tracked["{$pivot}:{$id}"] = true;
        }
        try {
            $result = $work();
        } finally {
            foreach ($ids as $id) {
                unset($this->tracked["{$pivot}:{$id}"]);
            }
        }
        $this->settle($before, $this->contribution($kind, $ids));

        return $result;
    }

    /**
     * The first half of a model event pair (an item or translation about to be written): lock the
     * item and read what it adds now. Null when the kind is not counted, the item is not one that
     * counts (a page), a track() around it already counts it, or an open batch will read it when it
     * closes (it went on or off the website in it: that reading is of its final state). $locked: the caller holds the row
     * lock already. $countsNow false: the item adds nothing now (it is in the trash), no read.
     *
     * @return array{kind: string, id: int, before: array<string, array{0: int, 1: int}>}|null
     */
    public function begin(string $pivot, int $id, bool $locked = false, bool $countsNow = true): ?array
    {
        $kind = $this->site->hasId() ? ($this->kinds()[$pivot] ?? null) : null;
        if ($kind === null || isset($this->tracked["{$pivot}:{$id}"]) || $this->isDeferred($pivot, $id)) {
            return null;
        }
        if (! $locked && $this->lock($kind, [$id]) === []) {
            return null;
        }

        return ['kind' => $pivot, 'id' => $id, 'before' => $countsNow ? $this->contribution($kind, [$id]) : []];
    }

    /**
     * The second half: read what the item adds now and add the difference. $countsAfter false: the
     * item adds nothing any more (just trashed or deleted), no read.
     *
     * @param  array{kind: string, id: int, before: array<string, array{0: int, 1: int}>}|null  $token
     */
    public function end(?array $token, bool $countsAfter = true): void
    {
        $kind = $token === null ? null : ($this->kinds()[$token['kind']] ?? null);
        if ($token === null || $kind === null) {
            return;
        }

        $this->settle($token['before'], $countsAfter ? $this->contribution($kind, [$token['id']]) : []);
    }

    /**
     * Run $work, holding back every change it makes until it is done; then they are written (one
     * update per row) — or handed to the enclosing batch. If $work throws, its changes are dropped
     * with it.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public function batch(Closure $work): mixed
    {
        $this->batches[] = ['changes' => [], 'deferred' => []];
        try {
            $result = $work();
        } catch (Throwable $e) {
            array_pop($this->batches);

            throw $e;
        }

        $done = array_pop($this->batches) ?? ['changes' => [], 'deferred' => []];
        $parent = array_key_last($this->batches);
        if ($parent !== null) {
            $this->batches[$parent]['changes'] = self::sum($this->batches[$parent]['changes'], $done['changes']);
            // What the enclosing batch saw of an item came first.
            foreach ($done['deferred'] as $pivot => $items) {
                $this->batches[$parent]['deferred'][$pivot] = ($this->batches[$parent]['deferred'][$pivot] ?? []) + $items;
            }

            return $result;
        }

        // The outermost batch: every item that went on or off the website in it adds what it adds now
        // and takes out what it added when the batch began — nothing if it first came on (it was off
        // then), and if it first went off, what it adds read as if still live (its filings and
        // translations are still there). Two reads per kind at most, whatever the number of rows. An
        // item is not written again after it went off in the same batch (no bulk action does).
        $changes = $done['changes'];
        foreach ($done['deferred'] as $pivot => $items) {
            $kind = $this->kinds()[$pivot] ?? null;
            if ($kind === null || $items === []) {
                continue;
            }
            $ids = array_keys($items);
            sort($ids);
            $wereLive = array_keys(array_filter($items, static fn (string $first): bool => $first === 'out'));
            sort($wereLive);
            $changes = self::sum($changes, $this->contribution($kind, $ids));
            if ($wereLive !== []) {
                foreach ($this->contribution($kind, $wereLive, asIfLive: true) as $key => [$own, $tree]) {
                    $changes = self::sum($changes, [$key => [-$own, -$tree]]);
                }
            }
        }
        $this->adjust($changes);

        return $result;
    }

    /**
     * For a live item leaving the website ('out': into the trash, or its status no longer published)
     * or coming back ('in'), whose row the caller has locked or is updating: true when nothing more is
     * to be done now — a track() around it counts it, or an open batch will read it when it closes.
     * False: count it now (begin()/end()).
     */
    public function deferred(string $direction, string $pivot, int $id): bool
    {
        if (! $this->site->hasId() || ! isset($this->kinds()[$pivot])) {
            return false;
        }
        if (isset($this->tracked["{$pivot}:{$id}"])) {
            return true;
        }
        $open = array_key_last($this->batches);
        if ($open === null) {
            return false;
        }
        $this->batches[$open]['deferred'][$pivot][$id] ??= $direction === 'out' ? 'out' : 'in';

        return true;
    }

    /**
     * What these items (filed through $pivot) add to the public totals now, without locking them —
     * for a set-based writer that has locked them itself (CategoryRelinker, a category move). Apply
     * the difference of two readings with settle().
     *
     * @param  list<int>  $ids
     * @return array<string, array{0: int, 1: int}> "taxonomy|locale" → [own, tree]
     */
    public function reading(string $pivot, array $ids): array
    {
        $kind = $this->kinds()[$pivot] ?? null;

        return $kind === null || $ids === [] ? [] : $this->contribution($kind, $ids);
    }

    /** Whether an open batch will read this item when it closes (deferred()). */
    private function isDeferred(string $pivot, int $id): bool
    {
        foreach ($this->batches as $batch) {
            if (isset($batch['deferred'][$pivot][$id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add the difference between two readings of the same items.
     *
     * @param  array<string, array{0: int, 1: int}>  $before
     * @param  array<string, array{0: int, 1: int}>  $after
     */
    public function settle(array $before, array $after): void
    {
        $changes = $after;
        foreach ($before as $key => [$own, $tree]) {
            $changes[$key] = [($changes[$key][0] ?? 0) - $own, ($changes[$key][1] ?? 0) - $tree];
        }
        $this->adjust($changes);
    }

    /**
     * The true totals of these categories, in every language they hold items in (one statement per
     * item kind): what the rows should hold. Rows not listed hold 0.
     *
     * @param  list<int>  $taxonomyIds
     * @return array<string, array{0: int, 1: int}> "taxonomy|locale" → [own, tree]
     */
    public function actual(array $taxonomyIds): array
    {
        if ($taxonomyIds === []) {
            return [];
        }

        $site = $this->site->id();
        $counts = [];
        foreach ($this->kinds() as $kind) {
            // The categories whose path runs through a wanted one (the taxonomy table is lookup-sized),
            // then the public items filed under them, each counted once per wanted category.
            $rows = DB::select(<<<SQL
                WITH below AS (
                    SELECT t.id, CAST(up.id AS bigint) AS taxonomy_id
                    FROM taxonomies AS t
                    CROSS JOIN LATERAL unnest(string_to_array(COALESCE(CAST(t.path AS text), CAST(t.id AS text)), '.')) AS up(id)
                    WHERE t.site_id = ? AND CAST(up.id AS bigint) = ANY(CAST(? AS bigint[]))
                )
                SELECT below.taxonomy_id, tr.locale,
                    COUNT(DISTINCT i.id) FILTER (WHERE p.taxonomy_id = below.taxonomy_id) AS own,
                    COUNT(DISTINCT i.id) AS tree
                FROM below
                JOIN {$kind['pivot']} AS p ON p.site_id = ? AND p.taxonomy_id = below.id{$kind['filed']}
                JOIN {$kind['items']} AS i ON i.site_id = p.site_id AND i.id = p.{$kind['column']} AND i.deleted_at IS NULL{$kind['item']}{$kind['status']}
                JOIN {$kind['translations']} AS tr ON tr.site_id = i.site_id AND tr.{$kind['column']} = i.id{$kind['translation']}
                GROUP BY below.taxonomy_id, tr.locale
                SQL, [$site, self::array($taxonomyIds), $site]);
            $kindCounts = self::rows($rows);
            if ($kind['section'] !== null) {
                // A wanted catalog root: its tree total is every public item of the kind.
                foreach (DB::select(<<<SQL
                    SELECT cs.taxonomy_id, tr.locale, COUNT(DISTINCT i.id) AS tree
                    FROM content_sections AS cs
                    JOIN {$kind['items']} AS i ON i.site_id = cs.site_id AND i.deleted_at IS NULL{$kind['item']}{$kind['status']}
                    JOIN {$kind['translations']} AS tr ON tr.site_id = i.site_id AND tr.{$kind['column']} = i.id{$kind['translation']}
                    WHERE cs.site_id = ? AND cs.key = ? AND cs.taxonomy_id = ANY(CAST(? AS bigint[]))
                    GROUP BY cs.taxonomy_id, tr.locale
                    SQL, [$site, $kind['section'], self::array($taxonomyIds)]) as $row) {
                    $row = (array) $row;
                    $key = Cast::int($row['taxonomy_id'] ?? null).'|'.Cast::string($row['locale'] ?? null);
                    $kindCounts[$key] = [$kindCounts[$key][0] ?? 0, Cast::int($row['tree'] ?? null)];
                }
            }
            $counts = self::sum($counts, $kindCounts);
        }

        return $counts;
    }

    /**
     * Count these categories' rows again and write the true numbers: lock the rows (in order), count,
     * write the ones that differ. A save that lands before the lock is in the count; one after it adds
     * its change to the written number. For a row that just appeared (a category translation created)
     * and the reconcile. Returns the rows written, with the numbers they held: "taxonomy|locale" →
     * [[own, tree] stored, [own, tree] true].
     *
     * @param  list<int>  $taxonomyIds
     * @return array<string, array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}>
     */
    public function recount(array $taxonomyIds, ?string $locale = null): array
    {
        if ($taxonomyIds === [] || ! $this->site->hasId()) {
            return [];
        }

        $site = $this->site->id();
        $stored = [];
        foreach (DB::select(
            'SELECT taxonomy_id, locale, public_own_count, public_tree_count FROM taxonomy_translations WHERE site_id = ? AND taxonomy_id = ANY(CAST(? AS bigint[]))'
            .($locale === null ? '' : ' AND locale = ?').' ORDER BY taxonomy_id, locale FOR NO KEY UPDATE',
            [$site, self::array($taxonomyIds), ...($locale === null ? [] : [$locale])],
        ) as $row) {
            $row = (array) $row;
            $stored[Cast::int($row['taxonomy_id'] ?? null).'|'.Cast::string($row['locale'] ?? null)] = [Cast::int($row['public_own_count'] ?? null), Cast::int($row['public_tree_count'] ?? null)];
        }
        if ($stored === []) {
            return [];
        }

        $true = $this->actual(array_values(array_unique(array_map(static fn (string $key): int => (int) explode('|', $key, 2)[0], array_keys($stored)))));
        $written = [];
        foreach ($stored as $key => $count) {
            $now = $true[$key] ?? [0, 0];
            if ($now !== $count) {
                [$id, $rowLocale] = explode('|', $key, 2);
                DB::update(
                    'UPDATE taxonomy_translations SET public_own_count = ?, public_tree_count = ? WHERE site_id = ? AND taxonomy_id = ? AND locale = ?',
                    [max($now[0], 0), max($now[1], 0), $site, (int) $id, $rowLocale],
                );
                $written[$key] = [$count, $now];
            }
        }

        return $written;
    }

    /**
     * Add each change to its row — now, or at the end of the open batch.
     *
     * @param  array<string, array{0: int, 1: int}>  $changes  "taxonomy|locale" → [own, tree]
     */
    private function adjust(array $changes): void
    {
        $changes = array_filter($changes, static fn (array $change): bool => $change !== [0, 0]);
        if ($changes === []) {
            return;
        }

        $open = array_key_last($this->batches);
        if ($open !== null) {
            $this->batches[$open]['changes'] = self::sum($this->batches[$open]['changes'], $changes);

            return;
        }

        // (category, language) order: see the class comment.
        uksort($changes, static function (string $a, string $b): int {
            [$idA, $localeA] = explode('|', $a, 2);
            [$idB, $localeB] = explode('|', $b, 2);

            return [(int) $idA, $localeA] <=> [(int) $idB, $localeB];
        });
        $site = $this->site->id();
        foreach ($changes as $key => [$own, $tree]) {
            [$id, $locale] = explode('|', $key, 2);
            DB::update(
                'UPDATE taxonomy_translations SET public_own_count = GREATEST(public_own_count + ?, 0), public_tree_count = GREATEST(public_tree_count + ?, 0) WHERE site_id = ? AND taxonomy_id = ? AND locale = ?',
                [$own, $tree, $site, (int) $id, $locale],
            );
        }
    }

    /**
     * Lock these items' rows (id order) and return the ones that can count at all (a page cannot).
     *
     * @param  array{pivot: string, column: string, items: string, translations: string, lock: string, item: string, translation: string, status: string, filed: string, section: string|null}  $kind
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function lock(array $kind, array $ids): array
    {
        $locked = [];
        foreach (DB::select(
            "SELECT id FROM {$kind['items']} WHERE site_id = ? AND id = ANY(CAST(? AS bigint[])){$kind['lock']} ORDER BY id FOR UPDATE",
            [$this->site->id(), self::array($ids)],
        ) as $row) {
            $locked[] = Cast::int(((array) $row)['id'] ?? null);
        }

        return $locked;
    }

    /**
     * What these items add to the totals now: 1 to the own total of each category they are filed
     * under, and 1 to the tree total of each category on the paths of their filings (once per item),
     * in each language they are public in. One statement. $asIfLive: as if the items were neither in
     * the trash nor off the website (what items just trashed or unpublished added).
     *
     * @param  array{pivot: string, column: string, items: string, translations: string, lock: string, item: string, translation: string, status: string, filed: string, section: string|null}  $kind
     * @param  list<int>  $ids
     * @return array<string, array{0: int, 1: int}>
     */
    private function contribution(array $kind, array $ids, bool $asIfLive = false): array
    {
        $live = $asIfLive ? $kind['item'] : ' AND i.deleted_at IS NULL'.$kind['item'].$kind['status'];
        $site = $this->site->id();
        $bindings = [$site, self::array($ids), $site];
        // Every public item of a kind with a catalog root counts once in the root's tree total.
        $root = '';
        if ($kind['section'] !== null) {
            $root = <<<SQL
                UNION
                SELECT i.id, tr.locale, cs.taxonomy_id
                FROM {$kind['items']} AS i
                JOIN {$kind['translations']} AS tr ON tr.site_id = i.site_id AND tr.{$kind['column']} = i.id{$kind['translation']}
                JOIN content_sections AS cs ON cs.site_id = i.site_id AND cs.key = ? AND cs.taxonomy_id IS NOT NULL
                WHERE i.site_id = ? AND i.id = ANY(CAST(? AS bigint[])){$live}
                SQL;
            array_push($bindings, $kind['section'], $site, self::array($ids));
        }
        // Table and column names are plain identifiers (CategoryDependents checks them). DISTINCT and
        // UNION (not ALL) in `above`: each item once per category.
        $rows = DB::select(<<<SQL
            WITH filed AS (
                SELECT DISTINCT i.id AS item, tr.locale, p.taxonomy_id
                FROM {$kind['items']} AS i
                JOIN {$kind['translations']} AS tr ON tr.site_id = i.site_id AND tr.{$kind['column']} = i.id{$kind['translation']}
                JOIN {$kind['pivot']} AS p ON p.site_id = i.site_id AND p.{$kind['column']} = i.id{$kind['filed']}
                WHERE i.site_id = ? AND i.id = ANY(CAST(? AS bigint[])){$live}
            ), above AS (
                SELECT DISTINCT filed.item, filed.locale, CAST(up.id AS bigint) AS taxonomy_id
                FROM filed
                JOIN taxonomies AS t ON t.site_id = ? AND t.id = filed.taxonomy_id
                CROSS JOIN LATERAL unnest(string_to_array(COALESCE(CAST(t.path AS text), CAST(t.id AS text)), '.')) AS up(id)
                {$root}
            )
            SELECT taxonomy_id, locale, SUM(own) AS own, SUM(tree) AS tree FROM (
                SELECT taxonomy_id, locale, COUNT(*) AS own, 0 AS tree FROM filed GROUP BY taxonomy_id, locale
                UNION ALL
                SELECT taxonomy_id, locale, 0 AS own, COUNT(*) AS tree FROM above GROUP BY taxonomy_id, locale
            ) AS c
            GROUP BY taxonomy_id, locale
            SQL, $bindings);

        return self::rows($rows);
    }

    /**
     * Posts, then every kind a module registered with a translations table, by pivot. The SQL
     * fragments are literals (the partial post indexes match a generic plan too).
     *
     * @return array<string, array{pivot: string, column: string, items: string, translations: string, lock: string, item: string, translation: string, status: string, filed: string, section: string|null}>
     */
    private function kinds(): array
    {
        $kinds = ['entry_taxonomies' => [
            'pivot' => 'entry_taxonomies', 'column' => 'entry_id', 'items' => 'entries', 'translations' => 'entry_translations',
            'lock' => " AND type = 'post'", 'item' => " AND i.type = 'post'", 'status' => " AND i.status = 'published'",
            'translation' => " AND tr.status = 'published'", 'filed' => ' AND p.position = 0', 'section' => null,
        ]];
        foreach ($this->dependents->all() as $kind) {
            if ($kind['translations'] === null) {
                continue;
            }
            $kinds[$kind['pivot']] = [
                'pivot' => $kind['pivot'], 'column' => $kind['column'], 'items' => $kind['items'], 'translations' => $kind['translations'],
                'lock' => '', 'item' => '', 'status' => " AND i.status = 'published'",
                'translation' => '', 'filed' => ' AND p.position < '.Entry::FACET_POSITION_BASE, 'section' => $kind['section'],
            ];
        }

        return $kinds;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, array{0: int, 1: int}>
     */
    private static function rows(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $counts[Cast::int($row['taxonomy_id'] ?? null).'|'.Cast::string($row['locale'] ?? null)] = [Cast::int($row['own'] ?? null), Cast::int($row['tree'] ?? null)];
        }

        return $counts;
    }

    /**
     * Two maps of [own, tree] added together.
     *
     * @param  array<string, array{0: int, 1: int}>  $into
     * @param  array<string, array{0: int, 1: int}>  $add
     * @return array<string, array{0: int, 1: int}>
     */
    private static function sum(array $into, array $add): array
    {
        foreach ($add as $key => [$own, $tree]) {
            $into[$key] = [($into[$key][0] ?? 0) + $own, ($into[$key][1] ?? 0) + $tree];
        }

        return $into;
    }

    /** @param  list<int>  $ids */
    private static function array(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }
}
