<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Menus\Concerns\EditsMenuTree;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuTree;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\DB;

/**
 * Delete an item together with its whole branch — the children are not promoted to the top level
 * (which is what the parent foreign key alone would do). The remaining siblings are renumbered.
 * Returns how many items were removed, so the screen can say so.
 */
final readonly class DeleteMenuItem
{
    use EditsMenuTree;

    public function handle(MenuItem $item): int
    {
        return DB::transaction(function () use ($item): int {
            $this->lockMenu($item->menu_id);
            $branch = MenuTree::of($item->menu_id)->branchOf($item->id);
            $parent = $item->fresh()?->parent_id;

            $deleted = Cast::int(MenuItem::query()->whereKey($branch)->delete());
            $this->renumber($item->menu_id, $parent);

            Audit::record('cms.menu_item.deleted', [
                'auditable_type' => MenuItem::class,
                'auditable_id' => $item->id,
                'meta' => ['items' => $deleted],
            ]);

            return $deleted;
        });
    }
}
