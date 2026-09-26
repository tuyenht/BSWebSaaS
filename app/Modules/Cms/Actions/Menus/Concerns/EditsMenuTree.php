<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus\Concerns;

use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Shared\Support\Cast;

/**
 * Shared steps of the actions that change a menu's tree. Callers run inside a transaction.
 */
trait EditsMenuTree
{
    /** Serialise tree edits per menu: two editors moving items at once must not interleave. */
    private function lockMenu(int $menuId): void
    {
        Menu::query()->whereKey($menuId)->lockForUpdate()->value('id');
    }

    /**
     * Number the children of one parent 0..n in the given order (by current position when none
     * is given), so positions never keep gaps or duplicates.
     *
     * @param  list<int>|null  $order
     */
    private function renumber(int $menuId, ?int $parentId, ?array $order = null): void
    {
        $order ??= array_map(static fn (mixed $id): int => Cast::int($id), MenuItem::query()
            ->where('menu_id', $menuId)
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->orderBy('position')->orderBy('id')
            ->pluck('id')->all());

        foreach (array_values($order) as $position => $id) {
            MenuItem::query()->whereKey($id)->update(['parent_id' => $parentId, 'position' => $position]);
        }
    }
}
