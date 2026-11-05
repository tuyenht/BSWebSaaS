<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Categories;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;

/**
 * One vocabulary's categories read in ONE query, for the Chuyên mục screen's read models (the tree,
 * the "Chuyển tới" targets, the delete preview), which must cost the same number of queries for 10
 * categories as for 1,000. Walks here are in memory over the same `parent_id` the write path trusts
 * (TaxonomyTree); every change is still checked again by the action, inside the tree lock.
 *
 * A parent loop in damaged data ends a walk instead of spinning; a category whose parent is in
 * another vocabulary (an old import) counts as top level here.
 */
final class CategoryForest
{
    /** @var array<int, Taxonomy> by id */
    private array $rows = [];

    /** @var array<int, list<int>> parent id (0 = top level) → children ids, in their order */
    private array $children = [];

    /** @var array<int, string> section root id → section key */
    private array $sectionRoots = [];

    /**
     * @param  array<int, string>  $sectionRoots  root category id → section key
     */
    public static function load(string $vocabulary, array $sectionRoots = []): self
    {
        $forest = new self;
        $forest->sectionRoots = $sectionRoots;

        $rows = Taxonomy::query()
            ->where('vocabulary', $vocabulary)
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'ulid', 'vocabulary', 'parent_id', 'position', 'list_template', 'detail_template', 'cover_media_id', 'items_count']);
        foreach ($rows as $row) {
            $forest->rows[$row->id] = $row;
        }
        foreach ($forest->rows as $id => $row) {
            $forest->children[$forest->parentOf($id) ?? 0][] = $id;
        }

        return $forest;
    }

    public function has(int $id): bool
    {
        return isset($this->rows[$id]);
    }

    public function get(int $id): ?Taxonomy
    {
        return $this->rows[$id] ?? null;
    }

    /** @return array<int, Taxonomy> */
    public function all(): array
    {
        return $this->rows;
    }

    /** The parent inside this vocabulary, null at the top level. */
    public function parentOf(int $id): ?int
    {
        $parent = $this->rows[$id]->parent_id ?? null;

        return $parent !== null && isset($this->rows[$parent]) ? $parent : null;
    }

    /** @return list<int> the children of $id (the top level for null), in order */
    public function children(?int $id): array
    {
        return $this->children[$id ?? 0] ?? [];
    }

    /** @return list<int> the ids above $id, root first */
    public function ancestors(int $id): array
    {
        $above = [];
        $seen = [$id => true];
        $parent = $this->parentOf($id);
        while ($parent !== null && ! isset($seen[$parent])) {
            array_unshift($above, $parent);
            $seen[$parent] = true;
            $parent = $this->parentOf($parent);
        }

        return $above;
    }

    /** 1 for a top-level category. */
    public function level(int $id): int
    {
        return count($this->ancestors($id)) + 1;
    }

    /** @return list<int> $id and everything under it, in drawing order */
    public function branch(int $id): array
    {
        $out = [];
        $seen = [];
        $walk = function (int $at) use (&$walk, &$out, &$seen): void {
            if (isset($seen[$at])) {
                return;
            }
            $seen[$at] = true;
            $out[] = $at;
            foreach ($this->children($at) as $child) {
                $walk($child);
            }
        };
        $walk($id);

        return $out;
    }

    /** The section key a category files posts under: its own or its nearest ancestor's binding. */
    public function sectionOf(int $id): ?string
    {
        foreach ([$id, ...array_reverse($this->ancestors($id))] as $at) {
            if (isset($this->sectionRoots[$at])) {
                return $this->sectionRoots[$at];
            }
        }

        return null;
    }

    public function isSectionRoot(int $id): bool
    {
        return isset($this->sectionRoots[$id]);
    }

    /**
     * How many items have their main category somewhere in these categories (items_count, the
     * counter kept with every write — never a live count).
     *
     * @param  list<int>  $ids
     */
    public function items(array $ids): int
    {
        $sum = 0;
        foreach ($ids as $id) {
            $sum += Cast::int($this->rows[$id]->items_count ?? 0);
        }

        return $sum;
    }

    public function byUlid(string $ulid): ?Taxonomy
    {
        foreach ($this->rows as $row) {
            if ($row->ulid === $ulid) {
                return $row;
            }
        }

        return null;
    }
}
