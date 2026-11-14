<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Menus;

use App\Modules\Admin\Support\ProductsTabSwitch;
use App\Modules\Cms\Actions\Menus\ApplyMenuDraft;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\MenuItemTranslation;
use App\Modules\Cms\Navigation\MenuItemOptions;
use App\Modules\Cms\Navigation\MenuLimits;
use App\Modules\Cms\Navigation\MenuRules;
use App\Modules\Cms\Navigation\MenuVersion;
use App\Modules\Cms\Navigation\SpecialLinks;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Theming\MenuAreas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * What the Trình đơn screen loads: the site's languages, the theme's areas with what each shows
 * per language, the site's menus, the special links the site can offer, and — for the menu being
 * edited — its version token, limits, items (in display order, the same {key, parent} shape a
 * draft's tree takes), the source behind each item and its trash. Everything is keyed by ulid,
 * never by id. A fixed number of queries whatever the menu's size (Query Budget): the menus, the
 * assignments, the items, their labels, the trash, a few per kind of source (pages and posts also their redirects), the site's details.
 */
final readonly class MenuScreen
{
    /** Trash rows the screen lists (newest first); the rest wait for their 30 days. */
    public const int TRASH_LIMIT = 100;

    public function __construct(
        private SiteLocale $siteLocales,
        private SiteIdentity $identity,
        private SpecialLinks $specials,
        private ApplyMenuDraft $drafts,
        private MenuSources $sources,
        private ProductsTabSwitch $productsTab,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function load(?Menu $menu): array
    {
        $main = $this->siteLocales->default();
        $locales = $this->siteLocales->enabled();
        $menus = Menu::query()->orderBy('id')->get(['id', 'ulid', 'name', 'locales', 'meta']);
        $assignments = $this->assignments();
        $ulids = [];
        foreach ($menus as $row) {
            $ulids[$row->id] = $row->ulid;
        }

        return [
            'locales' => array_map(fn (string $code): array => [
                'code' => $code,
                'name' => is_string(config("locales.labels.{$code}")) ? config("locales.labels.{$code}") : Str::upper($code),
                'main' => $code === $main,
            ], $locales),
            'areas' => $this->areas($assignments, $ulids, $locales),
            'productsTab' => $this->productsTab(),
            'menus' => $menus->map(fn (Menu $row): array => [
                'key' => $row->ulid,
                'name' => $row->name,
                'locales' => $row->locales,
                'autoAdd' => ($row->meta['auto_add_pages'] ?? false) === true,
                'areas' => MenuLimits::areasShowing($row->id, $assignments),
            ])->all(),
            'specials' => $this->specialLinks($main),
            'maxDepth' => MenuRules::MAX_DEPTH,
            'menu' => $menu === null ? null : $this->menu($menu, $assignments, $locales, $main),
        ];
    }

    /**
     * Every assignment row of the site, as MenuLimits reads them.
     *
     * @return list<array{region: string, locale: string, menu: int|null, follows: string|null}>
     */
    public function assignments(): array
    {
        $rows = [];
        foreach (MenuAssignment::query()->orderBy('id')->get(['region_key', 'locale', 'menu_id', 'same_as_region']) as $row) {
            $rows[] = ['region' => $row->region_key, 'locale' => $row->locale, 'menu' => $row->menu_id, 'follows' => $row->same_as_region];
        }

        return $rows;
    }

    /**
     * What the phone menu's area says about the "Sản phẩm" tab: a note when the site shows the tab,
     * and whether it may link to the product settings page where the tab is switched (only for
     * someone who may change site settings; others read the note without the link).
     *
     * @return array{note: bool, link: bool}
     */
    public function productsTab(): array
    {
        $on = $this->productsTab->isOn();

        return ['note' => $on, 'link' => $on && Gate::allows('settings.manage')];
    }

    /**
     * Each declared area with, per language, what it shows: {menu: ulid} (its own menu),
     * {follows: area} ("Dùng trình đơn chung") or nothing ({} — the website then shows the main
     * language's choice, or for the phone menu the main menu).
     *
     * @param  list<array{region: string, locale: string, menu: int|null, follows: string|null}>  $assignments
     * @param  array<int, string>  $ulids  menu id → ulid
     * @param  list<string>  $locales
     * @return list<array<string, mixed>>
     */
    private function areas(array $assignments, array $ulids, array $locales): array
    {
        $byArea = [];
        foreach ($assignments as $row) {
            $byArea[$row['region']][$row['locale']] = $row['menu'] !== null
                ? ['menu' => $ulids[$row['menu']] ?? null]
                : ['follows' => $row['follows']];
        }

        $out = [];
        foreach (MenuAreas::all() as $key => $area) {
            $shows = [];
            foreach ($locales as $locale) {
                $shows[$locale] = $byArea[$key][$locale] ?? (object) [];
            }
            $out[] = [
                'key' => $key,
                'group' => __($area['group']),
                'label' => __($area['label']),
                'help' => __($area['help']),
                'icon' => $area['icon'],
                'depth' => $area['depth'],
                'mega' => $area['mega'],
                'canSame' => $area['canSame'],
                'advanced' => $area['advanced'],
                'shows' => $shows,
            ];
        }

        return $out;
    }

    /**
     * The special links ("Liên kết đặc biệt") with whether this site has what each needs: a call
     * link needs a phone number in Cài đặt, and so on. One the site lacks may still be added; the
     * website hides it until the detail is filled in.
     *
     * @return list<array{key: string, label: string, help: string, available: bool}>
     */
    private function specialLinks(string $main): array
    {
        $contact = $this->identity->for($main) ?? [];
        $out = [];
        foreach (array_keys(MenuRules::DYNAMIC) as $key) {
            $out[] = [
                'key' => $key,
                'label' => __('menus.special_'.$key),
                'help' => __('menus.special_'.$key.'_help'),
                'available' => $this->specials->resolve($key, $contact) !== null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{region: string, locale: string, menu: int|null, follows: string|null}>  $assignments
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function menu(Menu $menu, array $assignments, array $locales, string $main): array
    {
        [$items, $labels] = $this->drafts->read($menu->id);
        $trash = MenuItem::onlyTrashed()->where('menu_id', $menu->id)
            ->orderByDesc('deleted_at')->orderByDesc('id')->limit(self::TRASH_LIMIT)
            ->get(['id', 'ulid', 'parent_id', 'link_type', 'routable_id', 'dynamic_key', 'url', 'deleted_at', 'created_at']);
        if ($items->concat($trash)->contains(fn (MenuItem $item): bool => $item->ulid === null)) {
            $this->fillUlids($items->concat($trash));
        }
        $trashLabels = [];
        if ($trash->isNotEmpty()) {
            foreach (MenuItemTranslation::query()->whereIn('menu_item_id', $trash->modelKeys())->where('locale', $main)->get(['menu_item_id', 'label']) as $label) {
                $trashLabels[$label->menu_item_id] = $label->label;
            }
        }

        $sources = $this->sources->of($items->concat($trash), $this->menuLocales($menu, $locales), $main);
        $byItem = [];
        foreach ($labels as $label) {
            $byItem[$label->menu_item_id][$label->locale] = $label->label;
        }
        $ulidOf = $items->pluck('ulid', 'id')->all();

        $rows = [];
        foreach ($this->displayOrder($items) as $item) {
            $rows[] = [
                'key' => $item->ulid,
                'parent' => $item->parent_id === null ? null : ($ulidOf[$item->parent_id] ?? null),
                'link' => $this->link($item, $sources[$item->id] ?? null),
                'labels' => (object) ($byItem[$item->id] ?? []),
                'options' => MenuItemOptions::forScreen($item->meta ?? []),
                'source' => $sources[$item->id] ?? null,
            ];
        }

        return [
            'key' => $menu->ulid,
            'name' => $menu->name,
            'locales' => $menu->locales,
            'autoAdd' => ($menu->meta['auto_add_pages'] ?? false) === true,
            'version' => MenuVersion::of($menu, $items, $labels),
            'limits' => MenuLimits::fromAssignments($menu->id, $assignments),
            'items' => $rows,
            'trash' => $this->trashRows($trash, $trashLabels, $sources),
        ];
    }

    /**
     * The languages the menu is written for: its own list, else every enabled language.
     *
     * @param  list<string>  $enabled
     * @return list<string>
     */
    public function menuLocales(Menu $menu, array $enabled): array
    {
        return $menu->locales === null ? $enabled : array_values(array_intersect($enabled, $menu->locales));
    }

    /**
     * @param  array{kind: string, ref: string|null, title: string|null, slug: string|null, state: string, untranslated: list<string>, summary: array<string, string>, moved: bool}|null  $source
     * @return array<string, string|null>
     */
    private function link(MenuItem $item, ?array $source): array
    {
        return match ($item->link_type) {
            'url' => ['type' => 'url', 'url' => $item->url],
            'dynamic' => ['type' => 'special', 'key' => $item->dynamic_key],
            default => ['type' => $item->link_type, 'ref' => $source['ref'] ?? null],
        };
    }

    /**
     * Items parents first, siblings by position: the order the screen draws and a draft's tree takes.
     *
     * @param  Collection<int, MenuItem>  $items
     * @return list<MenuItem>
     */
    private function displayOrder(Collection $items): array
    {
        // A live row whose parent is not live (in the trash, from older data) is drawn at the top
        // level: every live row must reach the screen, since a draft has to name each one.
        $live = $items->pluck('id')->flip();
        $children = [];
        foreach ($items->sortBy([['position', 'asc'], ['id', 'asc']]) as $item) {
            $parent = $item->parent_id !== null && $live->has($item->parent_id) ? $item->parent_id : 0;
            $children[$parent][] = $item;
        }
        $out = [];
        $walk = function (int $parent) use (&$walk, &$out, $children): void {
            foreach ($children[$parent] ?? [] as $item) {
                $out[] = $item;
                $walk($item->id);
            }
        };
        $walk(0);

        return $out;
    }

    /**
     * Trash rows the screen can restore: each removal once, by its top item (the items removed with
     * it come back with it), with its label and how many items it holds.
     *
     * @param  Collection<int, MenuItem>  $trash
     * @param  array<int, string>  $labelOf  item id → its label in the main language
     * @param  array<int, array{kind: string, ref: string|null, title: string|null, slug: string|null, state: string, untranslated: list<string>, summary: array<string, string>, moved: bool}>  $sources
     * @return list<array{key: string|null, label: string, removedAt: string|null, count: int}>
     */
    private function trashRows(Collection $trash, array $labelOf, array $sources): array
    {
        /** @var array<int, MenuItem> $byId */
        $byId = [];
        foreach ($trash as $item) {
            $byId[$item->id] = $item;
        }
        $count = [];
        $roots = [];
        foreach ($trash as $item) {
            $root = $item;
            while ($root->parent_id !== null && isset($byId[$root->parent_id])
                && $byId[$root->parent_id]->deleted_at?->eq($root->deleted_at ?? '') === true) {
                $root = $byId[$root->parent_id];
            }
            $count[$root->id] = ($count[$root->id] ?? 0) + 1;
            $roots[$root->id] = $root;
        }

        $out = [];
        foreach ($roots as $id => $item) {
            $label = $labelOf[$id] ?? '';
            if ($label === '') {
                $label = $sources[$id]['title'] ?? ($item->link_type === 'dynamic' ? __('menus.special_'.$item->dynamic_key) : $item->url ?? '');
            }
            $out[] = [
                'key' => $item->ulid,
                'label' => $label,
                'removedAt' => $item->deleted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'count' => $count[$id],
            ];
        }

        return $out;
    }

    /**
     * Rows made before items had a ulid get one now, so every row of the screen has its key (one
     * statement per such row, once: `cms:fill-ulids` normally got there first).
     *
     * @param  Collection<int, MenuItem>  $items
     */
    private function fillUlids(Collection $items): void
    {
        foreach ($items as $item) {
            if ($item->ulid === null) {
                $item->ulid = (string) Str::ulid();
                MenuItem::query()->whereKey($item->id)->whereNull('ulid')->update(['ulid' => $item->ulid]);
            }
        }
    }
}
