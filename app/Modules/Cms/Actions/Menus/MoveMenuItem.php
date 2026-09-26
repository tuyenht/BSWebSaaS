<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Menus\Concerns\EditsMenuTree;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuRules;
use App\Modules\Cms\Navigation\MenuTree;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\DB;

/**
 * Move an item — with everything under it — to a position among a parent's children (null = top
 * level) in the same menu. Both the old and the new sibling lists are renumbered 0..n. The move is
 * refused when it would put the item under itself or its own branch, or make the tree deeper than
 * MenuRules::MAX_DEPTH levels.
 */
final readonly class MoveMenuItem
{
    use EditsMenuTree;

    /**
     * @throws MenuChangeRejectedException
     */
    public function handle(MenuItem $item, ?MenuItem $parent, int $position): void
    {
        if ($parent !== null && ($parent->menu_id !== $item->menu_id || $parent->id === $item->id)) {
            throw new MenuChangeRejectedException('parent_invalid');
        }

        DB::transaction(function () use ($item, $parent, $position): void {
            $this->lockMenu($item->menu_id);
            $tree = MenuTree::of($item->menu_id);

            if ($parent !== null && in_array($parent->id, $tree->branchOf($item->id), true)) {
                throw new MenuChangeRejectedException('parent_invalid');
            }
            $depth = $parent === null ? 0 : $tree->depthOf($parent->id) + 1;
            if ($depth + $tree->heightOf($item->id) > MenuRules::MAX_DEPTH) {
                throw new MenuChangeRejectedException('depth_exceeded');
            }

            $oldParent = $item->fresh()?->parent_id;
            $siblings = array_map(static fn (mixed $id): int => Cast::int($id), MenuItem::query()
                ->where('menu_id', $item->menu_id)
                ->whereKeyNot($item->id)
                ->when($parent === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parent?->id))
                ->orderBy('position')->orderBy('id')
                ->pluck('id')->all());
            array_splice($siblings, max(0, min($position, count($siblings))), 0, [$item->id]);

            $this->renumber($item->menu_id, $parent?->id, array_values($siblings));
            if ($oldParent !== $parent?->id) {
                $this->renumber($item->menu_id, $oldParent);
            }
            $item->refresh();

            Audit::record('cms.menu_item.moved', [
                'auditable_type' => MenuItem::class,
                'auditable_id' => $item->id,
                'meta' => ['parent' => $parent?->id, 'position' => $item->position],
            ]);
        });
    }
}
