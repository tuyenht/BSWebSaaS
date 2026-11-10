<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Menus\Concerns\EditsMenuTree;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuRules;
use App\Modules\Cms\Navigation\MenuTree;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Remove an item together with its whole branch: they move to the menu's trash (deleted_at), all
 * with the same time, in one transaction. The children are not promoted to the top level. The
 * remaining siblings are renumbered. The trash keeps them MenuRules::TRASH_DAYS days, for
 * {@see RestoreMenuItem}, before `menus:purge-trash` deletes them for good. Returns how many
 * items were removed, so the screen can say so. Only someone who may manage menus can remove.
 */
final readonly class DeleteMenuItem
{
    use EditsMenuTree;

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, MenuItem $item): int
    {
        Gate::forUser($actor)->authorize('manage', Menu::class);

        return DB::transaction(function () use ($item): int {
            $this->lockMenu($item->menu_id);
            $branch = MenuTree::of($item->menu_id)->branchOf($item->id);
            $parent = $item->fresh()?->parent_id;

            // The soft-delete scope turns this into one UPDATE: every row gets the same deleted_at,
            // which is how a restore knows the branch that went with the item.
            $deleted = Cast::int(MenuItem::query()->whereKey($branch)->delete());
            $this->renumber($item->menu_id, $parent);

            Audit::record('cms.menu_item.deleted', [
                'auditable_type' => MenuItem::class,
                'auditable_id' => $item->id,
                'meta' => ['items' => $deleted, 'trash_days' => MenuRules::TRASH_DAYS],
            ]);

            return $deleted;
        });
    }
}
