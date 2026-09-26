<?php

declare(strict_types=1);

namespace App\Modules\Cms\Navigation;

use App\Modules\Cms\Models\MenuItem;

/**
 * One menu's item tree as id → parent id, read in a single query, for the write path's depth and
 * loop checks. Menus are small (tens to a few hundred items), so the whole shape fits in memory.
 */
final readonly class MenuTree
{
    /**
     * @param  array<int, int|null>  $parents
     */
    private function __construct(private array $parents) {}

    public static function of(int $menuId): self
    {
        /** @var array<int, int|null> $parents */
        $parents = MenuItem::query()->where('menu_id', $menuId)->pluck('parent_id', 'id')->all();

        return new self($parents);
    }

    /** 0 for a top-level item. */
    public function depthOf(int $id): int
    {
        $depth = 0;
        $seen = [];
        while (($parent = $this->parents[$id] ?? null) !== null && ! isset($seen[$parent])) {
            $seen[$parent] = true;
            $id = $parent;
            $depth++;
        }

        return $depth;
    }

    /** Levels in the branch rooted at $id: 1 for an item without children. */
    public function heightOf(int $id): int
    {
        $height = 1;
        foreach ($this->childrenOf($id) as $child) {
            $height = max($height, 1 + $this->heightOf($child));
        }

        return $height;
    }

    /**
     * The item and everything under it.
     *
     * @return list<int>
     */
    public function branchOf(int $id): array
    {
        $branch = [$id];
        foreach ($this->childrenOf($id) as $child) {
            array_push($branch, ...$this->branchOf($child));
        }

        return $branch;
    }

    /**
     * @return list<int>
     */
    private function childrenOf(int $id): array
    {
        return array_keys(array_filter($this->parents, fn (?int $parent): bool => $parent === $id));
    }
}
