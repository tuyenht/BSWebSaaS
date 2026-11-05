<?php

declare(strict_types=1);

namespace App\Modules\Cms\Listeners;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Menus\Concerns\EditsMenuTree;
use App\Modules\Cms\Events\EntryPublished;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Shared\Support\Cast;

/**
 * "Tự thêm trang mới": a page that goes public is added at the end of the top level of every menu
 * of the site that has auto_add_pages on, with no label of its own (the website shows the page's
 * title, in each language). Pages only, never posts. Once only: a menu that already holds the page
 * (or holds it in its trash, because an editor removed it) is left alone, so publishing again, or
 * taking a page offline and back, never adds a second item or undoes a removal.
 */
final class AddPublishedPageToMenu
{
    use EditsMenuTree;

    public function handle(EntryPublished $event): void
    {
        if ($event->type !== 'page') {
            return;
        }

        $menus = Menu::query()->orderBy('id')->get(['id', 'handle', 'meta'])
            ->filter(fn (Menu $menu): bool => ($menu->meta['auto_add_pages'] ?? false) === true);

        foreach ($menus as $menu) {
            $this->lockMenu($menu->id);
            $held = MenuItem::withTrashed()->where('menu_id', $menu->id)
                ->where('routable_type', 'entry')->where('routable_id', $event->entryId)->exists();
            if ($held) {
                continue;
            }
            $last = MenuItem::query()->where('menu_id', $menu->id)->whereNull('parent_id')->max('position');
            $item = MenuItem::query()->create([
                'menu_id' => $menu->id,
                'parent_id' => null,
                'position' => $last === null ? 0 : Cast::int($last) + 1,
                'link_type' => 'entry',
                'routable_type' => 'entry',
                'routable_id' => $event->entryId,
                'meta' => [],
            ]);

            Audit::record('cms.menu_item.auto_added', [
                'auditable_type' => MenuItem::class,
                'auditable_id' => $item->id,
                'meta' => ['menu' => $menu->handle],
            ]);
        }
    }
}
