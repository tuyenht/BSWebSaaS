<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\Menu;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * "Tự thêm trang mới vào cuối trình đơn": switch, for one menu, whether a page appears at the end
 * of its top level when it is published (AddPublishedPageToMenu). Kept in menus.meta.auto_add_pages
 * because the screen sets it per menu; nothing filters menus by it in SQL (the listener reads the
 * site's few menus and picks them in PHP).
 */
final readonly class SetMenuAutoAdd
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, Menu $menu, bool $on): void
    {
        Gate::forUser($actor)->authorize('update', $menu);

        $meta = $menu->meta ?? [];
        if ($on) {
            $meta['auto_add_pages'] = true;
        } else {
            unset($meta['auto_add_pages']);
        }
        $menu->forceFill(['meta' => $meta])->save();

        Audit::record('cms.menu.auto_add', [
            'auditable_type' => Menu::class,
            'auditable_id' => $menu->id,
            'meta' => ['handle' => $menu->handle, 'on' => $on],
        ]);
    }
}
