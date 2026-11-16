<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Support\Entries\SectionBranch;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * A list's categories as its category filter and category column show them: one read of the tree
 * (names in the default language, the `items_count` counter of each), in the order of the website.
 * The number beside a category is the counter summed over its subtree — every record not in the
 * Trash whose main category is that one or lies under it, whatever its status; no record is counted.
 *
 * The tree follows `parent_id`, the source of truth the ltree path mirrors, so a category whose path
 * was never written still has its children. A category without a `ulid` yet (filled by
 * `cms:fill-ulids`) cannot be chosen: the page never carries a bigint id.
 */
final class CategoryTree
{
    /** A website's categories are few; the read stops here whatever the data. */
    public const int LIMIT = 2000;

    /** @var array<int, array{id: int, ulid: string|null, parent: int|null, name: string, own: int}> */
    private array $nodes = [];

    /** @var array<int, list<int>> parent id → child ids, in the order read */
    private array $children = [];

    /** @var array<string, int> ulid → id */
    private array $byUlid = [];

    /** The section's own category (a posts list): its id and name; it is the list itself, not a choice. */
    private ?int $rootId = null;

    private string $rootName = '';

    /**
     * The product categories: one statement, at most LIMIT categories in menu order. Past that limit
     * the later categories are not read: they are not offered in the filter, their products cannot be
     * filtered by them, and their children (read, if within the limit) are listed at the top.
     */
    public static function products(int $siteId, string $locale): self
    {
        $rows = Taxonomy::query()
            ->where('taxonomies.vocabulary', 'product_cat')
            ->leftJoin('taxonomy_translations as tt', fn (JoinClause $join) => $join->on('tt.taxonomy_id', '=', 'taxonomies.id')
                ->where('tt.site_id', '=', $siteId)->where('tt.locale', '=', $locale))
            ->orderBy('taxonomies.position')->orderBy('taxonomies.id')
            ->limit(self::LIMIT)
            ->toBase()->get(['taxonomies.id', 'taxonomies.ulid', 'taxonomies.parent_id', 'taxonomies.items_count', 'tt.name']);

        return self::fromRows($rows->all(), null);
    }

    /** No categories (a list that has none: pages). */
    public static function none(): self
    {
        return new self;
    }

    /**
     * The categories of a post section: its own category and everything the section files under it
     * (SectionBranch — the same walk that decides which posts the list holds), one statement, at most
     * LIMIT categories in menu order. The section's own category is not a choice: its children are the
     * top of the tree. A section built on no category reads every post category of the website.
     */
    public static function postSection(int $siteId, string $locale, ?int $rootId): self
    {
        if ($rootId === null) {
            $rows = Taxonomy::query()
                ->where('taxonomies.vocabulary', 'post_cat')
                ->leftJoin('taxonomy_translations as tt', fn (JoinClause $join) => $join->on('tt.taxonomy_id', '=', 'taxonomies.id')
                    ->where('tt.site_id', '=', $siteId)->where('tt.locale', '=', $locale))
                ->orderBy('taxonomies.position')->orderBy('taxonomies.id')
                ->limit(self::LIMIT)
                ->toBase()->get(['taxonomies.id', 'taxonomies.ulid', 'taxonomies.parent_id', 'taxonomies.items_count', 'tt.name']);

            return self::fromRows($rows->all(), null);
        }

        [$branch, $values] = SectionBranch::with($siteId, [$rootId]);
        $rows = DB::select($branch.<<<'SQL'
            SELECT t.id, t.ulid, t.parent_id, t.items_count, tt.name
            FROM branch
            JOIN taxonomies AS t ON t.id = branch.id AND t.site_id = ?
            LEFT JOIN taxonomy_translations AS tt ON tt.taxonomy_id = t.id AND tt.site_id = ? AND tt.locale = ?
            WHERE NOT branch.looped
            ORDER BY t.position, t.id
            LIMIT ?
            SQL, [...$values, $siteId, $siteId, $locale, self::LIMIT]);

        return self::fromRows($rows, $rootId);
    }

    /**
     * @param  array<array-key, mixed>  $rows  stdClass rows of id, ulid, parent_id, items_count, name
     */
    private static function fromRows(array $rows, ?int $rootId): self
    {
        $tree = new self;
        foreach ($rows as $row) {
            $row = (array) $row;
            $id = Cast::int($row['id'] ?? 0);
            $name = Cast::string($row['name'] ?? '') ?: Cast::string(__('catalog.unnamed'));
            if ($id === $rootId) {
                $tree->rootId = $id;
                $tree->rootName = $name;

                continue;
            }
            $parent = $row['parent_id'] ?? null;
            $tree->nodes[$id] = [
                'id' => $id,
                'ulid' => Cast::stringOrNull($row['ulid'] ?? null),
                'parent' => $parent === null ? null : Cast::int($parent),
                'name' => $name,
                'own' => Cast::int($row['items_count'] ?? 0),
            ];
        }
        foreach ($tree->nodes as $id => $node) {
            // a parent outside the read (or itself, or the section's own category) leaves the category at the top
            $parent = $node['parent'] !== null && $node['parent'] !== $id && isset($tree->nodes[$node['parent']]) ? $node['parent'] : 0;
            $tree->children[$parent][] = $id;
            $tree->nodes[$id]['parent'] = $parent === 0 ? null : $parent;
            if ($node['ulid'] !== null) {
                $tree->byUlid[$node['ulid']] = $id;
            }
        }

        return $tree;
    }

    /** Whether there is a category to choose. */
    public function isEmpty(): bool
    {
        return $this->byUlid === [];
    }

    /**
     * What a row shows for its main category: the path from the top of the tree ("Kiến thức gia công ›
     * Gia công CNC"), the section's own name when the post is filed on the section itself, null when the
     * category is not in the read (none, or another section's).
     */
    public function label(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        return $id === $this->rootId ? $this->rootName : $this->pathOf($id);
    }

    /**
     * Every category, parents before children, with its depth (0, 1, 2 — deeper ones indent as 2) and count.
     *
     * @return list<array{ulid: string, label: string, depth: int, count: int}>
     */
    public function options(): array
    {
        $out = [];
        $this->walk(0, 0, $out);

        return $out;
    }

    /**
     * @param  list<array{ulid: string, label: string, depth: int, count: int}>  $out
     * @param  array<int, true>  $seen
     */
    private function walk(int $parent, int $depth, array &$out, array $seen = []): void
    {
        foreach ($this->children[$parent] ?? [] as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $node = $this->nodes[$id];
            if ($node['ulid'] !== null) {
                $out[] = ['ulid' => $node['ulid'], 'label' => $node['name'], 'depth' => min(2, $depth), 'count' => $this->total($id)];
            }
            $this->walk($id, $depth + 1, $out, $seen + [$id => true]);
        }
    }

    /**
     * Every category as a choice for ONE main category: parents before children, with its own
     * counter (the products whose main category it is, not the subtree), its depth and the names
     * of the categories above it (for a search result that leaves the indent behind).
     *
     * @return list<array{ulid: string, label: string, depth: int, count: int, path: string}>
     */
    public function choices(): array
    {
        $out = [];
        $this->walkChoices(0, 0, $out);

        return $out;
    }

    /**
     * @param  list<array{ulid: string, label: string, depth: int, count: int, path: string}>  $out
     * @param  array<int, true>  $seen
     */
    private function walkChoices(int $parent, int $depth, array &$out, array $seen = []): void
    {
        foreach ($this->children[$parent] ?? [] as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $node = $this->nodes[$id];
            if ($node['ulid'] !== null) {
                $above = $node['parent'] === null ? null : $this->pathOf($node['parent']);
                $out[] = ['ulid' => $node['ulid'], 'label' => $node['name'], 'depth' => min(2, $depth), 'count' => $node['own'], 'path' => $above === null ? '' : $above.' › '];
            }
            $this->walkChoices($id, $depth + 1, $out, $seen + [$id => true]);
        }
    }

    /** The key of a category by its id (null: not in the read, or not given a key yet). */
    public function ulidOf(int $id): ?string
    {
        return $this->nodes[$id]['ulid'] ?? null;
    }

    /** The counter of a category and everything under it. */
    private function total(int $id): int
    {
        $sum = 0;
        foreach ($this->subtree($id) as $below) {
            $sum += $this->nodes[$below]['own'];
        }

        return $sum;
    }

    /**
     * A category and every category under it, as ids.
     *
     * @return list<int>
     */
    private function subtree(int $id): array
    {
        $out = [];
        $stack = [$id];
        while ($stack !== []) {
            $at = array_pop($stack);
            if (isset($out[$at])) {
                continue;
            }
            $out[$at] = $at;
            foreach ($this->children[$at] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_values($out);
    }

    /**
     * Keep only the keys of categories that exist (and can be chosen), once each.
     *
     * @param  array<mixed>  $ulids
     * @return list<string>
     */
    public function known(array $ulids): array
    {
        return array_values(array_unique(array_filter($ulids, fn (mixed $u): bool => is_string($u) && isset($this->byUlid[$u]))));
    }

    /**
     * The chosen categories and everything under them, as ids: what the list filters by.
     *
     * @param  list<string>  $ulids
     * @return list<int>
     */
    public function idsUnder(array $ulids): array
    {
        $ids = [];
        foreach ($ulids as $ulid) {
            if (isset($this->byUlid[$ulid])) {
                foreach ($this->subtree($this->byUlid[$ulid]) as $id) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * The keys of a category and everything under it.
     *
     * @return list<string>
     */
    public function keysUnder(string $ulid): array
    {
        if (! isset($this->byUlid[$ulid])) {
            return [];
        }

        return array_values(array_filter(array_map(fn (int $id): ?string => $this->nodes[$id]['ulid'], $this->subtree($this->byUlid[$ulid]))));
    }

    /**
     * The keys of the categories above one (its parent, that one's parent, …).
     *
     * @return list<string>
     */
    public function keysAbove(string $ulid): array
    {
        $out = [];
        $at = $this->byUlid[$ulid] ?? null;
        for ($guard = 0; $at !== null && $guard < 64; $guard++) {
            $at = $this->nodes[$at]['parent'] ?? null;
            $key = $at === null ? null : $this->nodes[$at]['ulid'];
            if ($key !== null) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * The names of a category and everything above it, top first, joined by " › " (null when the
     * category is not in the read). Read from the tree already in memory: no query, whatever the number of rows.
     */
    public function pathOf(int $id): ?string
    {
        if (! isset($this->nodes[$id])) {
            return null;
        }
        $names = [];
        $at = $id;
        for ($guard = 0; $at !== null && isset($this->nodes[$at]) && $guard < 64; $guard++) {
            array_unshift($names, $this->nodes[$at]['name']);
            $at = $this->nodes[$at]['parent'];
        }

        return implode(' › ', $names);
    }

    /**
     * Of the chosen categories, those whose parent is not chosen as well: what a chip is made for.
     *
     * @param  list<string>  $ulids
     * @return list<array{ulid: string, label: string}>
     */
    public function topmost(array $ulids): array
    {
        $chosen = array_flip($ulids);
        $out = [];
        foreach ($ulids as $ulid) {
            $id = $this->byUlid[$ulid] ?? null;
            if ($id === null) {
                continue;
            }
            $parent = $this->nodes[$id]['parent'];
            if ($parent !== null && isset($chosen[$this->nodes[$parent]['ulid'] ?? ''])) {
                continue;
            }
            $out[] = ['ulid' => $ulid, 'label' => $this->nodes[$id]['name']];
        }

        return $out;
    }
}
