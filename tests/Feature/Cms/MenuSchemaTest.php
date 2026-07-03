<?php

use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\MenuItemTranslation;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;

/*
 * ③a — menu schema invariants (design.md §13) on real Postgres: RLS cross-tenant isolation,
 * the link_type + single-target CHECKs, the per-(region,locale) unique assignment, and the
 * COMPOSITE same-site FK that stops an assignment binding ANOTHER tenant's menu (a single-
 * column FK would let that cross-site reference through, which WITH CHECK does not catch on
 * the foreign side). Constraint-violation cases let the exception propagate OUT of enter()
 * so the aborted tx rolls back cleanly (catching inside would hit "transaction is aborted").
 */

beforeEach(fn () => seedTenancy());

it('isolates menus, assignments, items and translations across tenants', function (): void {
    $tenancy = app(Tenancy::class);

    $ids = $tenancy->enter(1, function (): array {
        $menu = Menu::query()->create(['handle' => 'main-vi', 'name' => 'Menu A']);
        $item = MenuItem::query()->create([
            'menu_id' => $menu->id, 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 0,
        ]);
        $item->translations()->create(['locale' => 'vi', 'label' => 'Trang chủ']);
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id]);

        return ['menu' => $menu->id, 'item' => $item->id];
    });

    // Site B sees none of site A's navigation rows.
    $tenancy->enter(2, function (): void {
        expect(Menu::query()->count())->toBe(0)
            ->and(MenuAssignment::query()->count())->toBe(0)
            ->and(MenuItem::query()->count())->toBe(0)
            ->and(MenuItemTranslation::query()->count())->toBe(0);
    });

    // Site A still sees its own.
    $tenancy->enter(1, function () use ($ids): void {
        expect(Menu::query()->whereKey($ids['menu'])->exists())->toBeTrue()
            ->and(MenuItem::query()->whereKey($ids['item'])->exists())->toBeTrue();
    });
});

it('rejects an unknown link_type', function (): void {
    expect(fn () => app(Tenancy::class)->enter(1, function (): void {
        $menu = Menu::query()->create(['handle' => 'main-vi', 'name' => 'M']);
        MenuItem::query()->create([
            'menu_id' => $menu->id, 'link_type' => 'bogus', 'url' => '/x', 'position' => 0,
        ]);
    }))->toThrow(QueryException::class);
});

it('rejects an entry/taxonomy item with no routable target', function (): void {
    expect(fn () => app(Tenancy::class)->enter(1, function (): void {
        $menu = Menu::query()->create(['handle' => 'main-vi', 'name' => 'M']);
        MenuItem::query()->create([
            'menu_id' => $menu->id, 'link_type' => 'taxonomy', 'position' => 0,   // routable_* null
        ]);
    }))->toThrow(QueryException::class);
});

it('rejects a url item that also carries a routable target', function (): void {
    expect(fn () => app(Tenancy::class)->enter(1, function (): void {
        $menu = Menu::query()->create(['handle' => 'main-vi', 'name' => 'M']);
        MenuItem::query()->create([
            'menu_id' => $menu->id, 'link_type' => 'url', 'url' => 'https://x.test',
            'routable_type' => 'taxonomy', 'routable_id' => 1, 'position' => 0,
        ]);
    }))->toThrow(QueryException::class);
});

it('allows only one menu per region per locale (unique assignment)', function (): void {
    expect(fn () => app(Tenancy::class)->enter(1, function (): void {
        $a = Menu::query()->create(['handle' => 'main-vi', 'name' => 'A']);
        $b = Menu::query()->create(['handle' => 'alt-vi', 'name' => 'B']);
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $a->id]);
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $b->id]);
    }))->toThrow(QueryException::class);
});

it('allows only one label per item per locale (unique translation)', function (): void {
    expect(fn () => app(Tenancy::class)->enter(1, function (): void {
        $menu = Menu::query()->create(['handle' => 'main-vi', 'name' => 'M']);
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 0]);
        $item->translations()->create(['locale' => 'vi', 'label' => 'A']);
        $item->translations()->create(['locale' => 'vi', 'label' => 'B']);
    }))->toThrow(QueryException::class);
});

it('treats the menu handle as case-insensitive within a site', function (): void {
    expect(fn () => app(Tenancy::class)->enter(1, function (): void {
        Menu::query()->create(['handle' => 'main-vi', 'name' => 'A']);
        Menu::query()->create(['handle' => 'Main-VI', 'name' => 'B']);
    }))->toThrow(QueryException::class);
});

it("rejects an assignment that binds another tenant's menu (composite same-site FK)", function (): void {
    $tenancy = app(Tenancy::class);

    // A menu that lives in site B (committed).
    $bMenuId = $tenancy->enter(2, fn () => Menu::query()->create(['handle' => 'main-vi', 'name' => 'B menu'])->id);

    // Site A cannot bind it: the composite (site_id, menu_id) FK has no (1, bMenuId) row.
    expect(fn () => $tenancy->enter(1, fn () => MenuAssignment::query()->create(
        ['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $bMenuId],
    )))->toThrow(QueryException::class);
});

it('cascades assignments, items and translations when a menu is deleted', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $menu = Menu::query()->create(['handle' => 'main-vi', 'name' => 'M']);
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 0]);
        $item->translations()->create(['locale' => 'vi', 'label' => 'Trang chủ']);
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id]);

        $menu->delete();

        expect(MenuAssignment::query()->count())->toBe(0)
            ->and(MenuItem::query()->count())->toBe(0)
            ->and(MenuItemTranslation::query()->count())->toBe(0);
    });
});
