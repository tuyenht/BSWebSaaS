<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;

/*
 * Product routing — U1-c write path. RouteSync::syncProduct composes a product's route from the
 * catalog's permalink preset (section key `products`) using PRIMITIVES (primary category Taxonomy +
 * slug), so Cms routing never depends on the Catalog Product model. Product = FLAT `/slug` by
 * default; the `products` section opts into category / category_path / base per site_settings.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** A product_cat taxonomy (+ optional parent) with a vi translation. */
function prodCategory(string $slug, string $name = 'Danh mục', ?int $parentId = null): Taxonomy
{
    $cat = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0, 'parent_id' => $parentId]);
    $cat->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $cat;
}

/** Pin the catalog `products` section to a permalink preset value (e.g. ['pattern' => 'category']). */
function pinProducts(array $value): void
{
    SiteSetting::query()->updateOrCreate(
        ['group_name' => 'permalinks', 'key' => 'products'],
        ['value' => $value],
    );
}

/** Create a published product at $slug under an optional primary category; sync its route; return the path. */
function syncProductRoute(string $slug, ?Taxonomy $category = null): ?string
{
    $product = Product::query()->create(['status' => 'published']);
    $product->translations()->create(['locale' => 'vi', 'name' => 'SP '.$slug, 'slug' => $slug]);

    if ($category !== null) {
        $product->assignCategories([$category->id]);
    }

    $primary = $product->fresh()->taxonomies()->first();
    app(RouteSync::class)->syncProduct($product->id, $primary, 'vi', $slug);

    return SiteRoute::query()->where('product_id', $product->id)->value('path');
}

it('routes a product FLAT by default — no pin means /slug (even with a category)', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $cat = prodCategory('do-ga', 'Đồ gá');
        expect(syncProductRoute('jig-vf7', $cat))->toBe('/jig-vf7');
    });
});

it('routes a product under its category when `products` is pinned to category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pinProducts(['pattern' => 'category']);
        $cat = prodCategory('do-ga', 'Đồ gá');
        expect(syncProductRoute('jig-vf7', $cat))->toBe('/do-ga/jig-vf7');
    });
});

it('routes a product under the full category chain when pinned to category_path', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pinProducts(['pattern' => 'category_path']);
        $parent = prodCategory('san-pham', 'Sản phẩm');
        $child = prodCategory('do-ga', 'Đồ gá', $parent->id);
        expect(syncProductRoute('jig-vf7', $child))->toBe('/san-pham/do-ga/jig-vf7');
    });
});

it('routes a product under a fixed base segment when pinned to base (preserves /san-pham)', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pinProducts(['pattern' => 'base', 'base' => 'san-pham']);
        // A product_cat category exists but base ignores it — the An Vinh URL-preservation path.
        $cat = prodCategory('do-ga', 'Đồ gá');
        expect(syncProductRoute('jig-vf7', $cat))->toBe('/san-pham/jig-vf7');
    });
});

it('rejects a product path already owned by a different target (entry) — no silent overwrite', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        // An entry already owns /lien-he (flat page).
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => User::factory()->create()->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'LH', 'slug' => 'lien-he', 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($page->fresh(), 'vi', 'lien-he');

        // A product wanting the same flat path clashes → friendly exception, not a DB error.
        expect(fn () => syncProductRoute('lien-he'))->toThrow(RouteConflictException::class);
    });
});

it('moves a product route and records a 301 when its slug changes', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $product = Product::query()->create(['status' => 'published']);
        $product->translations()->create(['locale' => 'vi', 'name' => 'SP', 'slug' => 'cu']);
        $sync = app(RouteSync::class);

        expect($sync->syncProduct($product->id, null, 'vi', 'cu'))->toBe('/cu');
        expect($sync->syncProduct($product->id, null, 'vi', 'moi'))->toBe('/moi');

        // The route moved, and an old → new 301 was recorded.
        expect(SiteRoute::query()->where('product_id', $product->id)->value('path'))->toBe('/moi')
            ->and(Redirect::query()->where('from_path', '/cu')->where('to_path', '/moi')->where('status_code', 301)->exists())->toBeTrue();
    });
});
