<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Menus\Concerns\EditsMenuTree;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
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
 * "Khôi phục": bring an item back from the menu's trash with the branch that was removed with it
 * (the trashed items under it with the same deleted_at — a sub-item removed on its own earlier
 * stays in the trash, to be restored by itself). It goes back under its parent at its old
 * position; when the parent is in the trash too, or already deleted for good, or the tree there
 * would grow past MenuRules::MAX_DEPTH levels, it goes to the end of the top level of the same
 * menu instead, so it never becomes an item the tree cannot show. The siblings are renumbered. One transaction,
 * serialised with the other tree edits of the menu. Only someone who may manage menus can restore.
 * Returns how many items came back.
 */
final readonly class RestoreMenuItem
{
    use EditsMenuTree;

    /**
     * @throws AuthorizationException
     * @throws MenuChangeRejectedException
     */
    public function handle(User $actor, MenuItem $item): int
    {
        Gate::forUser($actor)->authorize('manage', Menu::class);

        return DB::transaction(function () use ($item): int {
            $this->lockMenu($item->menu_id);

            $trashed = MenuItem::onlyTrashed()->whereKey($item->id)->first(['id', 'menu_id', 'parent_id', 'position', 'deleted_at']);
            if ($trashed === null || $trashed->deleted_at === null) {
                throw new MenuChangeRejectedException('item_invalid');   // not in the trash (any more)
            }

            $branch = $this->branch($trashed);
            $tree = MenuTree::of($trashed->menu_id);   // the live tree, before the branch comes back
            $parentId = $trashed->parent_id;
            if ($parentId !== null && ! MenuItem::query()->whereKey($parentId)->where('menu_id', $trashed->menu_id)->exists()) {
                $parentId = null;
            }
            if ($parentId !== null && $tree->depthOf($parentId) + 1 + $this->height($branch, $trashed->id) > MenuRules::MAX_DEPTH) {
                $parentId = null;
            }

            $restored = Cast::int(MenuItem::onlyTrashed()->whereKey(array_keys($branch))->update(['deleted_at' => null]));

            $siblings = array_map(static fn (mixed $id): int => Cast::int($id), MenuItem::query()
                ->where('menu_id', $trashed->menu_id)
                ->whereKeyNot($trashed->id)
                ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
                ->orderBy('position')->orderBy('id')
                ->pluck('id')->all());
            $position = $parentId === $trashed->parent_id ? $trashed->position : count($siblings);
            array_splice($siblings, max(0, min($position, count($siblings))), 0, [$trashed->id]);
            $this->renumber($trashed->menu_id, $parentId, array_values($siblings));

            Audit::record('cms.menu_item.restored', [
                'auditable_type' => MenuItem::class,
                'auditable_id' => $trashed->id,
                'meta' => ['items' => $restored, 'to_top_level' => $parentId !== $trashed->parent_id],
            ]);

            return $restored;
        });
    }

    /**
     * The item and the trashed items under it that were removed in the same delete.
     *
     * @return array<int, int|null> id → parent id
     */
    private function branch(MenuItem $root): array
    {
        /** @var array<int, int|null> $parents */
        $parents = MenuItem::onlyTrashed()
            ->where('menu_id', $root->menu_id)
            ->where('deleted_at', $root->deleted_at)
            ->pluck('parent_id', 'id')->all();

        $branch = [$root->id => $root->parent_id];
        $frontier = [$root->id];
        while ($frontier !== []) {
            $next = [];
            foreach ($parents as $id => $parent) {
                if ($parent !== null && in_array($parent, $frontier, true) && ! array_key_exists($id, $branch)) {
                    $branch[$id] = $parent;
                    $next[] = $id;
                }
            }
            $frontier = $next;
        }

        return $branch;
    }

    /**
     * Levels in the branch rooted at $id: 1 for an item without children.
     *
     * @param  array<int, int|null>  $branch
     */
    private function height(array $branch, int $id): int
    {
        $height = 1;
        foreach ($branch as $child => $parent) {
            if ($parent === $id) {
                $height = max($height, 1 + $this->height($branch, $child));
            }
        }

        return $height;
    }
}
