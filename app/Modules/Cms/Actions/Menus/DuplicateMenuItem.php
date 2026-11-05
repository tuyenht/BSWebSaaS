<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Menus\Concerns\CopiesMenuItems;
use App\Modules\Cms\Actions\Menus\Concerns\EditsMenuTree;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuTree;
use App\Modules\Cms\Navigation\MenuVersion;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Nhân bản" an item: a copy of it and of everything under it (the trash stays behind), labels
 * (the copied item's own marked "(bản sao)") and options included, placed right after the original among its siblings, which are renumbered.
 * Same level as the original, so the depth cannot grow. Given the version the screen holds, a menu
 * changed since is refused (draft_stale), like a draft. Returns the copy.
 */
final readonly class DuplicateMenuItem
{
    use CopiesMenuItems;
    use EditsMenuTree;

    public function __construct(private ApplyMenuDraft $drafts) {}

    /**
     * @throws AuthorizationException
     * @throws MenuChangeRejectedException
     */
    public function handle(User $actor, Menu $menu, MenuItem $item, ?string $version = null): MenuItem
    {
        Gate::forUser($actor)->authorize('update', $menu);
        if ($item->menu_id !== $menu->id) {
            throw new MenuChangeRejectedException('item_invalid');
        }

        return DB::transaction(function () use ($menu, $item, $version): MenuItem {
            $this->lockMenu($menu->id);
            $menu->refresh();
            [$items, $labels] = $this->drafts->read($menu->id);
            if ($version !== null && ! hash_equals(MenuVersion::of($menu, $items, $labels), $version)) {
                throw new MenuChangeRejectedException('draft_stale');
            }
            $original = $items->firstWhere('id', $item->id);
            if (! $original instanceof MenuItem) {
                throw new MenuChangeRejectedException('item_invalid');   // trashed or gone since
            }

            $branch = MenuTree::of($menu->id)->branchOf($original->id);
            $copies = $this->copyItems($menu->id, $items->whereIn('id', $branch)->values(), [$original->id => $original->parent_id], [$original->id => $original->position + 1], markRoots: true);
            $copyId = $copies[$original->id];

            $siblings = [];
            foreach ($items->where('parent_id', $original->parent_id)->sortBy([['position', 'asc'], ['id', 'asc']]) as $sibling) {
                $siblings[] = $sibling->id;
            }
            $at = array_search($original->id, $siblings, true);
            array_splice($siblings, $at === false ? count($siblings) : $at + 1, 0, [$copyId]);
            $this->renumber($menu->id, $original->parent_id, $siblings);

            Audit::record('cms.menu_item.duplicated', [
                'auditable_type' => MenuItem::class,
                'auditable_id' => $copyId,
                'meta' => ['menu' => $menu->handle, 'items' => count($copies)],
            ]);

            return MenuItem::query()->findOrFail($copyId);
        });
    }
}
