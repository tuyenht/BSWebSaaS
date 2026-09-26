<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\Menus\AssignMenu;
use App\Modules\Cms\Actions\Menus\DeleteMenu;
use App\Modules\Cms\Actions\Menus\DeleteMenuItem;
use App\Modules\Cms\Actions\Menus\MoveMenuItem;
use App\Modules\Cms\Actions\Menus\SaveMenu;
use App\Modules\Cms\Actions\Menus\SaveMenuItem;
use App\Modules\Cms\Data\MenuItemData;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Navigation\MenuResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Facades\DB;

/*
 * The menu write path behind the Menus screen (design.md §13, model-B): menus, items with their
 * target and per-language labels, moving items in the tree, and binding a menu to a
 * (region × language). The data rules hold whoever calls: a target must exist on this site, an
 * external link must be safe, the tree stays within five levels and never loops, deleting an item
 * takes its branch with it, and a menu still shown somewhere is not deleted by accident.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
});

/** Run inside site 1. */
function inSiteOne(Closure $callback): mixed
{
    return app(Tenancy::class)->enter(1, $callback);
}

/** Why a menu change was refused, or null when it went through. */
function menuRefusal(Closure $change): ?string
{
    try {
        $change();
    } catch (MenuChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

function newMenu(string $name = 'Menu chính'): Menu
{
    return app(SaveMenu::class)->handle(null, $name);
}

function addItem(Menu $menu, MenuItemData $data): MenuItem
{
    return app(SaveMenuItem::class)->handle($menu, null, $data);
}

function homeLink(string $label = 'Trang chủ', ?MenuItem $parent = null): MenuItemData
{
    return new MenuItemData(linkType: 'dynamic', dynamicKey: 'home', labels: ['vi' => $label], parent: $parent);
}

it('creates a menu with a stable handle and renames it without changing that handle', function (): void {
    inSiteOne(function (): void {
        $first = newMenu('Menu chính');
        $second = newMenu('Menu chính');

        expect($first->handle)->toBe('menu-chinh')
            ->and($second->handle)->toBe('menu-chinh-2');

        $renamed = app(SaveMenu::class)->handle($first, 'Menu đầu trang');
        expect($renamed->name)->toBe('Menu đầu trang')->and($renamed->handle)->toBe('menu-chinh');

        expect(menuRefusal(fn () => newMenu('   ')))->toBe('name_invalid')
            ->and(menuRefusal(fn () => newMenu(str_repeat('a', 121))))->toBe('name_invalid')
            ->and(menuRefusal(fn () => app(SaveMenu::class)->handle(null, 'Menu tiếng Anh', ['en'])))->toBe('locale_invalid');
    });
});

it('adds every kind of link, each pointing at something that exists on this site', function (): void {
    inSiteOne(function (): void {
        $menu = newMenu();
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $news = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);

        addItem($menu, homeLink());
        addItem($menu, new MenuItemData(linkType: 'entry', targetId: $page->id, labels: ['vi' => 'Giới thiệu']));
        addItem($menu, new MenuItemData(linkType: 'taxonomy', targetId: $news->id, labels: []));
        addItem($menu, new MenuItemData(linkType: 'url', url: 'https://zalo.me/0900000000', labels: ['vi' => 'Zalo'], newTab: true));

        $items = $menu->items()->orderBy('position')->get();
        expect($items->pluck('link_type')->all())->toBe(['dynamic', 'entry', 'taxonomy', 'url'])
            ->and($items->pluck('position')->all())->toBe([0, 1, 2, 3])
            ->and($items[1]->routable_type)->toBe('entry')
            ->and($items[3]->meta['target'] ?? null)->toBe('_blank');
    });
});

it('refuses a link that goes nowhere or somewhere unsafe', function (MenuItemData $data, string $reason): void {
    $foreignCategory = app(Tenancy::class)->enter(2, fn () => Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0])->id);

    inSiteOne(function () use ($data, $reason, $foreignCategory): void {
        $menu = newMenu();
        if ($data->targetId === -1) {
            $data = new MenuItemData(linkType: 'taxonomy', targetId: $foreignCategory);
        }

        expect(menuRefusal(fn () => addItem($menu, $data)))->toBe($reason)
            ->and($menu->items()->count())->toBe(0);
    });
})->with([
    'script link' => [new MenuItemData(linkType: 'url', url: 'javascript:alert(1)'), 'url_invalid'],
    'data link' => [new MenuItemData(linkType: 'url', url: 'data:text/html,hi'), 'url_invalid'],
    'empty link' => [new MenuItemData(linkType: 'url', url: '  '), 'url_invalid'],
    'unknown computed link' => [new MenuItemData(linkType: 'dynamic', dynamicKey: 'cart'), 'target_invalid'],
    'unknown kind' => [new MenuItemData(linkType: 'banner', targetId: 1), 'target_invalid'],
    'missing page' => [new MenuItemData(linkType: 'entry', targetId: 999999), 'target_invalid'],
    'missing product' => [new MenuItemData(linkType: 'product', targetId: 999999), 'target_invalid'],
    "another site's category" => [new MenuItemData(linkType: 'taxonomy', targetId: -1), 'target_invalid'],
]);

it('keeps a label per editing language; an empty label falls back to the target name', function (): void {
    inSiteOne(function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $menu = newMenu();
        $item = addItem($menu, new MenuItemData(linkType: 'dynamic', dynamicKey: 'home', labels: ['vi' => 'Trang chủ', 'en' => 'Home']));

        expect($item->translations()->orderBy('locale')->pluck('label', 'locale')->all())->toBe(['en' => 'Home', 'vi' => 'Trang chủ']);

        app(SaveMenuItem::class)->handle($menu, $item, new MenuItemData(linkType: 'dynamic', dynamicKey: 'home', labels: ['vi' => 'Trang nhà', 'en' => '']));
        expect($item->translations()->pluck('label', 'locale')->all())->toBe(['vi' => 'Trang nhà']);

        expect(menuRefusal(fn () => addItem($menu, homeLink(str_repeat('x', 256)))))->toBe('label_invalid')
            ->and(menuRefusal(fn () => addItem($menu, new MenuItemData(linkType: 'dynamic', dynamicKey: 'home', labels: ['ja' => 'ホーム']))))->toBe('locale_invalid');
    });
});

it('turns opening in a new tab on and off', function (): void {
    inSiteOne(function (): void {
        $menu = newMenu();
        $item = addItem($menu, new MenuItemData(linkType: 'url', url: '/lien-he', newTab: true));
        app(SaveMenuItem::class)->handle($menu, $item, new MenuItemData(linkType: 'url', url: '/lien-he', newTab: false));

        expect($item->refresh()->meta)->not->toHaveKey('target');
    });
});

it('moves an item within and across branches and renumbers the siblings', function (): void {
    inSiteOne(function (): void {
        $menu = newMenu();
        $a = addItem($menu, homeLink('A'));
        $b = addItem($menu, homeLink('B'));
        $c = addItem($menu, homeLink('C'));

        app(MoveMenuItem::class)->handle($c, null, 0);
        expect($menu->items()->whereNull('parent_id')->orderBy('position')->get()->map(fn (MenuItem $i) => $i->translationFor('vi')?->label)->all())
            ->toBe(['C', 'A', 'B']);

        app(MoveMenuItem::class)->handle($b, $a, 0);
        expect($b->refresh()->parent_id)->toBe($a->id)->and($b->position)->toBe(0)
            ->and($menu->items()->whereNull('parent_id')->orderBy('position')->pluck('position')->all())->toBe([0, 1]);
    });
});

it('never lets the tree loop or grow past five levels', function (): void {
    inSiteOne(function (): void {
        $menu = newMenu();
        $level = [addItem($menu, homeLink('1'))];
        for ($depth = 2; $depth <= 5; $depth++) {
            $level[] = addItem($menu, homeLink((string) $depth, end($level)));
        }
        $other = newMenu('Menu chân trang');
        $foreignParent = addItem($other, homeLink('X'));

        expect(menuRefusal(fn () => addItem($menu, homeLink('6', $level[4]))))->toBe('depth_exceeded')
            ->and(menuRefusal(fn () => app(MoveMenuItem::class)->handle($level[0], $level[2], 0)))->toBe('parent_invalid')
            ->and(menuRefusal(fn () => app(MoveMenuItem::class)->handle($level[1], $level[1], 0)))->toBe('parent_invalid')
            ->and(menuRefusal(fn () => app(MoveMenuItem::class)->handle($level[1], $foreignParent, 0)))->toBe('parent_invalid')
            ->and(menuRefusal(fn () => addItem($menu, homeLink('Y', $foreignParent))))->toBe('parent_invalid');

        // The branch under level 2 is four levels tall: it fits under a top-level item (levels 2–5)
        // but not under a second-level one (levels 3–6).
        $root = addItem($menu, homeLink('R'));
        $rootChild = addItem($menu, homeLink('R1', $root));
        expect(menuRefusal(fn () => app(MoveMenuItem::class)->handle($level[1], $root, 1)))->toBeNull()
            ->and(menuRefusal(fn () => app(MoveMenuItem::class)->handle($level[1], $rootChild, 0)))->toBe('depth_exceeded');
    });
});

it('deletes an item together with its whole branch', function (): void {
    inSiteOne(function (): void {
        $menu = newMenu();
        $parent = addItem($menu, homeLink('Cha'));
        $child = addItem($menu, homeLink('Con', $parent));
        addItem($menu, homeLink('Cháu', $child));
        $sibling = addItem($menu, homeLink('Bên cạnh'));

        expect(app(DeleteMenuItem::class)->handle($parent))->toBe(3)
            ->and($menu->items()->pluck('id')->all())->toBe([$sibling->id])
            ->and($sibling->refresh()->position)->toBe(0);
    });
});

it('binds a menu to a region per language, and the website shows it', function (): void {
    inSiteOne(function (): void {
        $menu = newMenu();
        addItem($menu, homeLink());
        $footer = newMenu('Menu chân trang');

        app(AssignMenu::class)->handle('primary', 'vi', $menu);
        expect(app(MenuResolver::class)->resolve('primary', 'vi', 'vi', '/')[0]['label'])->toBe('Trang chủ');

        app(AssignMenu::class)->handle('primary', 'vi', $footer);
        expect(MenuAssignment::query()->where('region_key', 'primary')->pluck('menu_id')->all())->toBe([$footer->id]);

        app(AssignMenu::class)->handle('primary', 'vi', null);
        expect(MenuAssignment::query()->count())->toBe(0);

        expect(menuRefusal(fn () => app(AssignMenu::class)->handle('Primary Menu!', 'vi', $menu)))->toBe('region_invalid')
            ->and(menuRefusal(fn () => app(AssignMenu::class)->handle('primary', 'en', $menu)))->toBe('locale_invalid');
    });
});

it('keeps a menu that is still shown unless the deletion is confirmed', function (): void {
    inSiteOne(function (): void {
        $menu = newMenu();
        addItem($menu, homeLink());
        app(AssignMenu::class)->handle('primary', 'vi', $menu);

        expect(menuRefusal(fn () => app(DeleteMenu::class)->handle($menu)))->toBe('menu_in_use')
            ->and(Menu::query()->count())->toBe(1);

        app(DeleteMenu::class)->handle($menu, evenIfAssigned: true);
        expect(Menu::query()->count())->toBe(0)
            ->and(MenuItem::query()->count())->toBe(0)
            ->and(MenuAssignment::query()->count())->toBe(0);
    });
});

it('records every change in the audit log', function (): void {
    inSiteOne(function (): void {
        $menu = newMenu();
        $item = addItem($menu, homeLink());
        app(MoveMenuItem::class)->handle($item, null, 0);
        app(AssignMenu::class)->handle('primary', 'vi', $menu);
        app(DeleteMenuItem::class)->handle($item);
    });

    expect(AuditLog::query()->where('site_id', 1)->orderBy('id')->pluck('action')->all())->toBe([
        'cms.menu.saved', 'cms.menu_item.saved', 'cms.menu_item.moved', 'cms.menu.assigned', 'cms.menu_item.deleted',
    ]);
});

it('does not reach another site', function (): void {
    $foreignMenu = app(Tenancy::class)->enter(2, fn () => newMenu()->ulid);

    inSiteOne(fn () => expect(Menu::query()->where('ulid', $foreignMenu)->exists())->toBeFalse());
});
