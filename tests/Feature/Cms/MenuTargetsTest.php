<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Navigation\MenuResolver;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;

/*
 * Menus link to catalog products (design.md §13) and never to something a visitor cannot open.
 * A page taken offline or moved to the Trash keeps its route row, and so does a product — the
 * menu must drop those items instead of putting a 404 in the main navigation. A product item
 * with no label of its own shows the product's name, like pages and categories do. Cms learns
 * all of this about products only through what Catalog registers (MenuTargets).
 */

beforeEach(fn () => seedTenancy());

/** A primary menu for 'vi' on site 1; returns its id. */
function menuTargetsMenu(): int
{
    $menu = Menu::query()->create(['handle' => 'main-vi', 'name' => 'Menu chính']);
    MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id]);

    return $menu->id;
}

/** A product with a vi translation + route; returns its id. */
function menuTargetsProduct(string $name, string $slug, string $status = 'published'): int
{
    $product = Product::query()->create(['status' => $status, 'published_at' => now()]);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);
    app(RouteSync::class)->syncProduct($product->id, null, 'vi', $slug);

    return $product->id;
}

/** A page with a vi translation (status as given) + route; returns its id. */
function menuTargetsPage(string $title, string $path, string $status = 'published', string $translationStatus = 'published'): int
{
    $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'published_at' => now()]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => ltrim($path, '/'), 'status' => $translationStatus]);
    SiteRoute::query()->create(['locale' => 'vi', 'path' => $path, 'entry_id' => $entry->id]);

    return $entry->id;
}

/** Add a menu item pointing at a routable target, with an optional vi label. */
function menuTargetsItem(int $menuId, string $type, int $targetId, ?string $label, int $position): void
{
    $item = MenuItem::query()->create([
        'menu_id' => $menuId, 'link_type' => $type, 'routable_type' => $type, 'routable_id' => $targetId, 'position' => $position,
    ]);
    if ($label !== null) {
        $item->translations()->create(['locale' => 'vi', 'label' => $label]);
    }
}

/** @return list<array<string, mixed>> */
function menuTargetsTree(): array
{
    return app(MenuResolver::class)->resolve('primary', 'vi', 'vi', '/');
}

it('links a menu item to a published product at its storefront URL', function (): void {
    $tree = app(Tenancy::class)->enter(1, function (): array {
        $menu = menuTargetsMenu();
        menuTargetsItem($menu, 'product', menuTargetsProduct('Jig kiểm VF7', 'jig-kiem-vf7'), 'Jig VF7', 0);

        return menuTargetsTree();
    });

    expect($tree)->toHaveCount(1)
        ->and($tree[0]['label'])->toBe('Jig VF7')
        ->and($tree[0]['url'])->toBe('/jig-kiem-vf7');
});

it("shows the product's own name when the item has no label", function (): void {
    $tree = app(Tenancy::class)->enter(1, function (): array {
        $menu = menuTargetsMenu();
        menuTargetsItem($menu, 'product', menuTargetsProduct('Đồ gá hàn', 'do-ga-han'), null, 0);

        return menuTargetsTree();
    });

    expect($tree)->toHaveCount(1)->and($tree[0]['label'])->toBe('Đồ gá hàn');
});

it('drops a product that is not published or sits in the Trash', function (): void {
    $tree = app(Tenancy::class)->enter(1, function (): array {
        $menu = menuTargetsMenu();
        menuTargetsItem($menu, 'product', menuTargetsProduct('Nháp', 'nhap', status: 'draft'), 'Nháp', 0);

        $trashed = menuTargetsProduct('Đã xoá', 'da-xoa');
        Product::query()->findOrFail($trashed)->delete();
        menuTargetsItem($menu, 'product', $trashed, 'Đã xoá', 1);

        menuTargetsItem($menu, 'product', menuTargetsProduct('Còn bán', 'con-ban'), 'Còn bán', 2);

        return menuTargetsTree();
    });

    expect(collect($tree)->pluck('label')->all())->toBe(['Còn bán']);
});

it('drops a page taken offline, trashed, or not published in this language', function (): void {
    $tree = app(Tenancy::class)->enter(1, function (): array {
        $menu = menuTargetsMenu();
        menuTargetsItem($menu, 'entry', menuTargetsPage('Nháp', '/nhap', status: 'draft'), 'Nháp', 0);
        menuTargetsItem($menu, 'entry', menuTargetsPage('Bản dịch nháp', '/ban-dich-nhap', translationStatus: 'draft'), 'Bản dịch nháp', 1);

        $trashed = menuTargetsPage('Thùng rác', '/thung-rac');
        Entry::query()->findOrFail($trashed)->delete();
        menuTargetsItem($menu, 'entry', $trashed, 'Thùng rác', 2);

        menuTargetsItem($menu, 'entry', menuTargetsPage('Giới thiệu', '/gioi-thieu'), 'Giới thiệu', 3);

        return menuTargetsTree();
    });

    expect(collect($tree)->pluck('label')->all())->toBe(['Giới thiệu']);
});

it('resolves every target kind in the same number of queries however many items there are', function (): void {
    $queries = fn (int $items): int => app(Tenancy::class)->enter(1, function () use ($items): int {
        $menu = menuTargetsMenu();
        for ($i = 0; $i < $items; $i++) {
            menuTargetsItem($menu, 'product', menuTargetsProduct("Sản phẩm {$i}", "sp-{$i}"), null, $i * 2);
            menuTargetsItem($menu, 'entry', menuTargetsPage("Trang {$i}", "/trang-{$i}"), null, $i * 2 + 1);
        }

        menuTargetsTree();   // warm the request-scoped memos (default locale) so both runs count alike

        return countQueries(fn () => menuTargetsTree());
    });

    $small = $queries(1);
    seedTenancy();
    $large = $queries(6);

    expect($large)->toBe($small);
});

it('refuses a product link without a target, and a link whose target type disagrees with it', function (array $row): void {
    app(Tenancy::class)->enter(1, function () use ($row): void {
        $menu = menuTargetsMenu();

        expect(fn () => MenuItem::query()->create(['menu_id' => $menu, 'position' => 0] + $row))
            ->toThrow(QueryException::class, 'menu_items_target_check');
    });
})->with([
    'product without target' => [['link_type' => 'product']],
    'product pointing at an entry' => [['link_type' => 'product', 'routable_type' => 'entry', 'routable_id' => 1]],
    'entry pointing at a category' => [['link_type' => 'entry', 'routable_type' => 'taxonomy', 'routable_id' => 1]],
]);
