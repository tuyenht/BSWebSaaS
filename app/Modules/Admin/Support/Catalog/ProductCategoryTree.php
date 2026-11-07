<?php

namespace App\Modules\Admin\Support\Catalog;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Query\JoinClause;

/**
 * The product categories as the list's category filter shows them: one read of the tree (names in
 * the default language, the `items_count` counter of each), in the order of the website. The number
 * beside a category is the counter summed over its subtree — every product not in the Trash whose
 * main category is that one or lies under it, whatever its status; no product is counted.
 *
 * The tree follows `parent_id`, the source of truth the ltree path mirrors, so a category whose path
 * was never written still has its children. A category without a `ulid` yet (filled by
 * `cms:fill-ulids`) cannot be chosen: the page never carries a bigint id.
 */
final class ProductCategoryTree
{
    /** A website's product categories are few; the read stops here whatever the data. */
    public const int LIMIT = 2000;

    /** @var array<int, array{id: int, ulid: string|null, parent: int|null, name: string, own: int}> */
    private array $nodes = [];

    /** @var array<int, list<int>> parent id → child ids, in the order read */
    private array $children = [];

    /** @var array<string, int> ulid → id */
    private array $byUlid = [];

    /**
     * Read the tree: one statement, at most LIMIT categories in menu order. Past that limit the later
     * categories are not read: they are not offered in the filter, their products cannot be filtered by
     * them, and their children (read, if within the limit) are listed at the top.
     */
    public static function load(int $siteId, string $locale): self
    {
        $rows = Taxonomy::query()
            ->where('taxonomies.vocabulary', 'product_cat')
            ->leftJoin('taxonomy_translations as tt', fn (JoinClause $join) => $join->on('tt.taxonomy_id', '=', 'taxonomies.id')
                ->where('tt.site_id', '=', $siteId)->where('tt.locale', '=', $locale))
            ->orderBy('taxonomies.position')->orderBy('taxonomies.id')
            ->limit(self::LIMIT)
            ->toBase()->get(['taxonomies.id', 'taxonomies.ulid', 'taxonomies.parent_id', 'taxonomies.items_count', 'tt.name']);

        $tree = new self;
        foreach ($rows as $row) {
            $row = (array) $row;
            $id = Cast::int($row['id'] ?? 0);
            $parent = $row['parent_id'] ?? null;
            $tree->nodes[$id] = [
                'id' => $id,
                'ulid' => Cast::stringOrNull($row['ulid'] ?? null),
                'parent' => $parent === null ? null : Cast::int($parent),
                'name' => Cast::string($row['name'] ?? '') ?: __('catalog.unnamed'),
                'own' => Cast::int($row['items_count'] ?? 0),
            ];
        }
        foreach ($tree->nodes as $id => $node) {
            // a parent outside the read (or itself) leaves the category at the top
            $parent = $node['parent'] !== null && $node['parent'] !== $id && isset($tree->nodes[$node['parent']]) ? $node['parent'] : 0;
            $tree->children[$parent][] = $id;
            $tree->nodes[$id]['parent'] = $parent === 0 ? null : $parent;
            if ($node['ulid'] !== null) {
                $tree->byUlid[$node['ulid']] = $id;
            }
        }

        return $tree;
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
