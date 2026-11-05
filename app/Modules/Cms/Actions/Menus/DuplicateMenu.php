<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Menus\Concerns\CopiesMenuItems;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Nhân bản" a menu: a new menu named after it (":name (bản sao)"), with a copy of every item still
 * in it (the trash stays behind) and their labels, in the same shape. The copy is bound to no area,
 * so the website does not change, and it does not take new pages on publish (auto_add_pages is left
 * off: one page published should not land in two menus by surprise). Returns the new menu.
 */
final readonly class DuplicateMenu
{
    use CopiesMenuItems;

    public function __construct(private SaveMenu $saveMenu) {}

    /**
     * @throws AuthorizationException
     * @throws MenuChangeRejectedException
     */
    public function handle(User $actor, Menu $menu): Menu
    {
        Gate::forUser($actor)->authorize('create', Menu::class);

        return DB::transaction(function () use ($menu): Menu {
            $suffix = __('menus.copy_name', ['name' => '']);
            $room = max(1, SaveMenu::NAME_MAX - mb_strlen($suffix));
            $name = __('menus.copy_name', ['name' => mb_substr($menu->name, 0, $room)]);
            $copy = $this->saveMenu->handle(null, $name, $menu->locales);
            $meta = $menu->meta ?? [];
            unset($meta['auto_add_pages']);
            if ($meta !== []) {
                $copy->forceFill(['meta' => $meta])->save();
            }

            $items = MenuItem::query()->where('menu_id', $menu->id)
                ->get(['id', 'parent_id', 'position', 'link_type', 'routable_type', 'routable_id', 'url', 'dynamic_key', 'meta']);
            $ids = $items->pluck('id')->all();
            $roots = $items->filter(fn (MenuItem $item): bool => $item->parent_id === null || ! in_array($item->parent_id, $ids, true));
            $this->copyItems(
                $copy->id,
                $items,
                $roots->mapWithKeys(fn (MenuItem $item): array => [$item->id => null])->all(),
                $roots->mapWithKeys(fn (MenuItem $item): array => [$item->id => $item->position])->all(),
            );

            Audit::record('cms.menu.duplicated', [
                'auditable_type' => Menu::class,
                'auditable_id' => $copy->id,
                'meta' => ['from' => $menu->handle, 'handle' => $copy->handle, 'items' => $items->count()],
            ]);

            return $copy;
        });
    }
}
