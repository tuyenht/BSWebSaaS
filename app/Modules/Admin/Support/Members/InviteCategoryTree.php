<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Members;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The categories of one axis (post categories, or product categories) that the "Phạm vi làm việc"
 * block of the members screen offers — in the invite dialog and in a member's panel — as a tree in
 * the website's order: one read (names in the default language), each category with its depth and
 * the categories above it. A category whose parent is of another vocabulary (product categories
 * filed under a post category) sits at the top of its own axis's tree. The page addresses a category by its ulid, never its bigint id; a category without a
 * ulid yet (filled by `cms:fill-ulids`) is not offered. The tree follows `parent_id`, the source of
 * truth the ltree path mirrors.
 */
final class InviteCategoryTree
{
    /** A website's categories of one axis are few; the read stops here whatever the data. */
    public const int LIMIT = 2000;

    /** @var array<int, array{id: int, ulid: string, parent: int|null, name: string}> in the website's order */
    private array $nodes = [];

    /** @var array<int, list<int>> parent id (0 = top) → child ids */
    private array $children = [];

    /** @param  string  $vocabulary  CategoryScopes::POSTS or CategoryScopes::PRODUCTS */
    public static function load(int $siteId, string $locale, string $vocabulary = CategoryScopes::POSTS): self
    {
        if (! CategoryScopes::isAxis($vocabulary)) {
            throw new InvalidArgumentException("Members are not limited on the vocabulary [{$vocabulary}].");
        }
        $rows = Taxonomy::query()
            ->where('taxonomies.vocabulary', $vocabulary)
            ->whereNotNull('taxonomies.ulid')
            ->leftJoin('taxonomy_translations as tt', fn (JoinClause $join) => $join->on('tt.taxonomy_id', '=', 'taxonomies.id')
                ->where('tt.site_id', '=', $siteId)->where('tt.locale', '=', $locale))
            ->orderBy('taxonomies.position')->orderBy('taxonomies.id')
            ->limit(self::LIMIT)
            ->toBase()->get(['taxonomies.id', 'taxonomies.ulid', 'taxonomies.parent_id', 'tt.name']);

        $tree = new self;
        foreach ($rows as $row) {
            $row = (array) $row;
            $id = Cast::int($row['id'] ?? 0);
            $parent = $row['parent_id'] ?? null;
            $tree->nodes[$id] = [
                'id' => $id,
                'ulid' => Cast::string($row['ulid'] ?? ''),
                'parent' => $parent === null ? null : Cast::int($parent),
                'name' => Cast::string($row['name'] ?? '') ?: __('catalog.unnamed'),
            ];
        }
        foreach ($tree->nodes as $id => $node) {
            // a parent not read (past the limit) puts the child at the top
            $tree->children[$node['parent'] !== null && isset($tree->nodes[$node['parent']]) ? $node['parent'] : 0][] = $id;
        }

        return $tree;
    }

    /**
     * Every category depth first, each with its depth and the ids above it (nearest last).
     *
     * @return list<array{id: int, ulid: string, name: string, depth: int, above: list<int>}>
     */
    public function rows(): array
    {
        $out = [];
        $this->walk(0, [], $out);

        return $out;
    }

    /**
     * @param  list<int>  $above
     * @param  list<array{id: int, ulid: string, name: string, depth: int, above: list<int>}>  $out
     */
    private function walk(int $parent, array $above, array &$out): void
    {
        foreach ($this->children[$parent] ?? [] as $id) {
            $node = $this->nodes[$id];
            $out[] = ['id' => $id, 'ulid' => $node['ulid'], 'name' => $node['name'], 'depth' => count($above), 'above' => $above];
            if (count($above) < 64) {   // a cycle in bad data must not loop forever
                $this->walk($id, [...$above, $id], $out);
            }
        }
    }

    public function name(int $id): ?string
    {
        return $this->nodes[$id]['name'] ?? null;
    }

    /** The id of the category with this ulid, or null. */
    public function idOf(string $ulid): ?int
    {
        foreach ($this->nodes as $id => $node) {
            if ($node['ulid'] === strtoupper($ulid)) {
                return $id;
            }
        }

        return null;
    }

    public function ulidOf(int $id): ?string
    {
        return $this->nodes[$id]['ulid'] ?? null;
    }

    /**
     * The ulids picked, minus those under another picked category (choosing a category includes
     * everything below it) and those not in the tree, in the tree's order.
     *
     * @param  list<string>  $ulids
     * @return list<int> their ids
     */
    public function normalise(array $ulids): array
    {
        $picked = array_flip(array_map('strtoupper', $ulids));
        $ids = [];
        foreach ($this->rows() as $row) {
            if (! isset($picked[$row['ulid']])) {
                continue;
            }
            foreach ($row['above'] as $above) {
                if (isset($picked[$this->nodes[$above]['ulid']])) {
                    continue 2;
                }
            }
            $ids[] = $row['id'];
        }

        return $ids;
    }

    /** Text compared without case or Vietnamese marks, for the search box. */
    public static function fold(string $text): string
    {
        return Str::lower(Str::ascii(trim($text)));
    }
}
