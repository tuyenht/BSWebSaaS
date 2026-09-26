<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Menus\Concerns\EditsMenuTree;
use App\Modules\Cms\Data\MenuItemData;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuRules;
use App\Modules\Cms\Navigation\MenuTargets;
use App\Modules\Cms\Navigation\MenuTree;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Add an item to a menu (at the end of its parent's children) or change what an item points at,
 * how it reads in each editing language, and whether it opens in a new tab.
 *
 *  - entry / taxonomy / a kind another module registers (product): the target must exist on THIS
 *    site — looked up through the morph map, so Cms imports no module that owns a kind, and RLS
 *    keeps another site's rows out of reach;
 *  - url: a site-relative path, an anchor, or an http(s) / mailto / tel link (MenuRules::safeUrl);
 *  - dynamic: a computed link MenuRules knows (home).
 * A new item may not be the sixth level. Who may manage menus is the caller's check.
 */
final readonly class SaveMenuItem
{
    use EditsMenuTree;

    private const int TEXT_MAX = 255;

    public function __construct(
        private SiteLocale $siteLocales,
        private MenuTargets $targets,
    ) {}

    /**
     * @throws MenuChangeRejectedException
     */
    public function handle(Menu $menu, ?MenuItem $item, MenuItemData $data): MenuItem
    {
        if ($item !== null && $item->menu_id !== $menu->id) {
            throw new MenuChangeRejectedException('item_invalid');
        }
        $target = $this->target($data);
        $labels = $this->labels($data->labels);

        return DB::transaction(function () use ($menu, $item, $data, $target, $labels): MenuItem {
            $this->lockMenu($menu->id);
            $created = $item === null;
            $item ??= $this->newItem($menu, $data->parent);

            $meta = $item->meta ?? [];
            if ($data->newTab) {
                $meta['target'] = '_blank';
            } else {
                unset($meta['target']);
            }
            $item->forceFill([...$target, 'meta' => $meta])->save();

            foreach ($labels as $locale => $label) {
                if ($label === '') {
                    $item->translations()->where('locale', $locale)->delete();
                } else {
                    $item->translations()->updateOrCreate(['locale' => $locale], ['label' => $label]);
                }
            }

            Audit::record('cms.menu_item.saved', [
                'auditable_type' => MenuItem::class,
                'auditable_id' => $item->id,
                'meta' => ['menu' => $menu->handle, 'link_type' => $item->link_type, 'created' => $created],
            ]);

            return $item;
        });
    }

    private function newItem(Menu $menu, ?MenuItem $parent): MenuItem
    {
        if ($parent !== null) {
            if ($parent->menu_id !== $menu->id) {
                throw new MenuChangeRejectedException('parent_invalid');
            }
            if (MenuTree::of($menu->id)->depthOf($parent->id) + 1 >= MenuRules::MAX_DEPTH) {
                throw new MenuChangeRejectedException('depth_exceeded');
            }
        }

        $last = MenuItem::query()
            ->where('menu_id', $menu->id)
            ->when($parent === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parent?->id))
            ->max('position');

        return new MenuItem([
            'menu_id' => $menu->id,
            'parent_id' => $parent?->id,
            'position' => $last === null ? 0 : Cast::int($last) + 1,
        ]);
    }

    /**
     * The target columns for the item's link type, every other target column cleared (the
     * table's CHECK allows exactly one target per kind).
     *
     * @return array{link_type: string, routable_type: string|null, routable_id: int|null, url: string|null, dynamic_key: string|null}
     *
     * @throws MenuChangeRejectedException
     */
    private function target(MenuItemData $data): array
    {
        $none = ['routable_type' => null, 'routable_id' => null, 'url' => null, 'dynamic_key' => null];

        if ($data->linkType === 'url') {
            $url = MenuRules::safeUrl($data->url);
            if ($url === null || mb_strlen($url) > self::TEXT_MAX) {
                throw new MenuChangeRejectedException('url_invalid');
            }

            return ['link_type' => 'url', ...$none, 'url' => $url];
        }

        if ($data->linkType === 'dynamic') {
            if ($data->dynamicKey === null || ! array_key_exists($data->dynamicKey, MenuRules::DYNAMIC)) {
                throw new MenuChangeRejectedException('target_invalid');
            }

            return ['link_type' => 'dynamic', ...$none, 'dynamic_key' => $data->dynamicKey];
        }

        $kinds = ['entry', 'taxonomy', ...array_keys($this->targets->all())];
        $class = in_array($data->linkType, $kinds, true) ? Relation::getMorphedModel($data->linkType) : null;
        if ($class === null || $data->targetId === null || ! $class::query()->whereKey($data->targetId)->exists()) {
            throw new MenuChangeRejectedException('target_invalid');
        }

        return ['link_type' => $data->linkType, ...$none, 'routable_type' => $data->linkType, 'routable_id' => $data->targetId];
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, string>
     *
     * @throws MenuChangeRejectedException
     */
    private function labels(array $labels): array
    {
        $enabled = $this->siteLocales->enabled();
        $clean = [];
        foreach ($labels as $locale => $label) {
            if (! in_array($locale, $enabled, true)) {
                throw new MenuChangeRejectedException('locale_invalid');
            }
            $label = trim($label);
            if (mb_strlen($label) > self::TEXT_MAX) {
                throw new MenuChangeRejectedException('label_invalid');
            }
            $clean[$locale] = $label;
        }

        return $clean;
    }
}
