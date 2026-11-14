<?php

use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * site:load-content and a menu item's icon: the pack places it, a re-run is unchanged, a changed
 * icon updates the menu, and an icon staff set on the site counts as a staff edit (never wiped).
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
});

afterEach(fn () => ContentPacks::cleanup());

/** @return array<string, string|null> top-level label → stored icon of the primary menu */
function cpMenuIcons(int $siteId): array
{
    return ContentPacks::in($siteId, function (): array {
        $menuId = MenuAssignment::query()->where('region_key', 'primary')->where('locale', 'vi')->value('menu_id');
        $icons = [];
        foreach (MenuItem::query()->where('menu_id', $menuId)->whereNull('parent_id')->orderBy('position')->with('translations')->get() as $item) {
            $icons[(string) $item->translations->first()?->label] = $item->meta['icon'] ?? null;
        }

        return $icons;
    });
}

function cpSetFirstIcon(string $folder, string $icon): void
{
    ContentPacks::item($folder, 'menus.json', 'menu:primary', function (array $menu) use ($icon): array {
        $menu['items'][0]['icon'] = $icon;

        return $menu;
    });
}

it('stores the icon a pack gives a menu item, and a re-run is unchanged', function (): void {
    cpSetFirstIcon($this->folder, 'info');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
    $icons = cpMenuIcons($this->siteId);
    expect(array_values($icons)[0])->toBe('info')
        ->and(array_filter(array_slice($icons, 1)))->toBe([]);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'menu:primary'))->toBe('unchanged');
});

it('updates the menu when the pack changes an icon', function (): void {
    cpSetFirstIcon($this->folder, 'info');
    ContentPacks::load();

    cpSetFirstIcon($this->folder, 'phone');
    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'menu:primary'))->toBe('update');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(array_values(cpMenuIcons($this->siteId))[0])->toBe('phone');
});

it('treats an icon staff set on the site as a staff edit and keeps it', function (): void {
    ContentPacks::load();
    ContentPacks::in($this->siteId, function (): void {
        $menuId = MenuAssignment::query()->where('region_key', 'primary')->where('locale', 'vi')->value('menu_id');
        $item = MenuItem::query()->where('menu_id', $menuId)->whereNull('parent_id')->orderBy('position')->firstOrFail();
        $item->update(['meta' => ['icon' => 'email']]);
    });

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'menu:primary'))->toBe('skip:edited');

    ContentPacks::load();
    expect(array_values(cpMenuIcons($this->siteId))[0])->toBe('email');
});
