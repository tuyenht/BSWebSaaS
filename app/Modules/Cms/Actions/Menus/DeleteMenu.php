<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Menu;

/**
 * Delete a menu with its items and assignments (foreign keys cascade). A menu the website still
 * shows somewhere is kept unless the caller confirms — the region would otherwise fall back to the
 * default-language menu or show nothing, which should never happen by accident.
 */
final readonly class DeleteMenu
{
    /**
     * @throws MenuChangeRejectedException
     */
    public function handle(Menu $menu, bool $evenIfAssigned = false): void
    {
        if (! $evenIfAssigned && $menu->assignments()->exists()) {
            throw new MenuChangeRejectedException('menu_in_use');
        }

        $menu->delete();

        Audit::record('cms.menu.deleted', [
            'auditable_type' => Menu::class,
            'auditable_id' => $menu->id,
            'meta' => ['handle' => $menu->handle, 'name' => $menu->name],
        ]);
    }
}
