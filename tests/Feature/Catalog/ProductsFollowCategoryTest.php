<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\MoveTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\UpdateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\CategoryItemsOnDelete;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\CategoryDependents;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/*
 * Catalog registers its products in Cms's CategoryDependents, so a category renamed, moved or deleted
 * in Cms re-files and re-routes products too — in the same transaction, each moved address leaving a
 * 301 — while Cms imports nothing of Catalog. Only the presets that put categories in a product's
 * address (category / category_path) cause any route work; under flat nothing moves.
 */

beforeEach(function (): void {
    seedTenancy();
});

function pfcCategory(string $name, ?Taxonomy $parent = null): Taxonomy
{
    return app(CreateTaxonomy::class)->handle('product_cat', $parent, new TaxonomyData(names: ['vi' => $name]));
}

/** Pin the catalog's `products` permalink preset. */
function pfcPreset(string $pattern): void
{
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'products', 'value' => ['pattern' => $pattern]]);
    app(PermalinkConfig::class)->flush();
}

/** A product filed under $category with its route synced; $status other than published = a draft. */
function pfcProduct(string $slug, Taxonomy $category, string $status = 'published'): Product
{
    $product = Product::query()->create(['status' => $status, 'published_at' => now()]);
    $product->translations()->create(['locale' => 'vi', 'name' => $slug, 'slug' => $slug]);
    $product->assignCategories([$category->id]);
    app(RouteSync::class)->syncProduct($product->id, $product->primaryCategory(), 'vi', $slug);

    return $product;
}

function pfcRoute(Product $product): ?string
{
    return SiteRoute::query()->where('product_id', $product->id)->where('locale', 'vi')->value('path');
}

function pfcRedirect(string $from): ?string
{
    return Redirect::query()->where('locale', 'vi')->where('from_path', $from)->where('status_code', 301)->value('to_path');
}

it('is registered with Cms as a kind filed under categories', function (): void {
    expect(app(CategoryDependents::class)->all())->toHaveKey('product')
        ->and(app(CategoryDependents::class)->all()['product']['pivot'])->toBe('product_taxonomies');
});

it('moves a product with its renamed category under the category preset, leaving a 301', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pfcPreset('category');
        $jigs = pfcCategory('Đồ gá');
        $product = pfcProduct('jig-vf7', $jigs);
        $draft = pfcProduct('jig-nhap', $jigs, 'draft');
        expect(pfcRoute($product))->toBe('/do-ga/jig-vf7');

        app(UpdateTaxonomy::class)->handle($jigs, new TaxonomyData(slugs: ['vi' => 'do-ga-cnc']));

        expect(pfcRoute($product))->toBe('/do-ga-cnc/jig-vf7')
            ->and(pfcRedirect('/do-ga/jig-vf7'))->toBe('/do-ga-cnc/jig-vf7')
            ->and(pfcRedirect('/do-ga'))->toBe('/do-ga-cnc')
            // A draft keeps its address until it is published again (the editor re-syncs it then).
            ->and(pfcRoute($draft))->toBe('/do-ga/jig-nhap');
    });
});

it('follows a move under the category-path preset, but not under the category preset', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pfcPreset('category_path');
        $catalog = pfcCategory('Sản phẩm');
        $parts = pfcCategory('Phụ tùng');
        $jigs = pfcCategory('Đồ gá', $catalog);
        $product = pfcProduct('jig-vf7', $jigs);
        expect(pfcRoute($product))->toBe('/san-pham/do-ga/jig-vf7');

        app(MoveTaxonomy::class)->handle($jigs, $parts, 0);
        expect(pfcRoute($product))->toBe('/phu-tung/do-ga/jig-vf7')
            ->and(pfcRedirect('/san-pham/do-ga/jig-vf7'))->toBe('/phu-tung/do-ga/jig-vf7');

        SiteSetting::query()->where('group_name', 'permalinks')->where('key', 'products')->update(['value' => ['pattern' => 'category']]);
        app(PermalinkConfig::class)->flush();
        app(RouteSync::class)->syncProduct($product->id, $product->primaryCategory(), 'vi', 'jig-vf7');
        $redirects = Redirect::query()->count();

        app(MoveTaxonomy::class)->handle($jigs, $catalog, 0);
        expect(pfcRoute($product))->toBe('/do-ga/jig-vf7')
            ->and(Redirect::query()->count())->toBe($redirects);
    });
});

it('leaves products where they are under the flat preset', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $catalog = pfcCategory('Sản phẩm');
        $jigs = pfcCategory('Đồ gá', $catalog);
        $product = pfcProduct('jig-vf7', $jigs);

        app(UpdateTaxonomy::class)->handle($jigs, new TaxonomyData(slugs: ['vi' => 'do-ga-cnc']));
        app(MoveTaxonomy::class)->handle($jigs, null, 0);

        expect(pfcRoute($product))->toBe('/jig-vf7')
            ->and(Redirect::query()->pluck('from_path')->all())->toBe(['/do-ga']);
    });
});

it('re-files and re-routes products when their category is deleted', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pfcPreset('category');
        $jigs = pfcCategory('Đồ gá');
        $moulds = pfcCategory('Khuôn mẫu');
        $fixtures = pfcCategory('Kẹp');
        $moved = pfcProduct('jig-vf7', $jigs);
        $detached = pfcProduct('kep-nhanh', $fixtures);

        $result = app(DeleteTaxonomy::class)->handle($jigs, CategoryItemsOnDelete::Reassign, $moulds);
        expect($result['items'])->toBe(1)
            ->and(pfcRoute($moved))->toBe('/khuon-mau/jig-vf7')
            ->and(pfcRedirect('/do-ga/jig-vf7'))->toBe('/khuon-mau/jig-vf7')
            ->and(DB::table('product_taxonomies')->where('product_id', $moved->id)->pluck('position', 'taxonomy_id')->all())->toBe([$moulds->id => 0]);

        app(DeleteTaxonomy::class)->handle($fixtures, CategoryItemsOnDelete::Detach);
        expect(pfcRoute($detached))->toBe('/kep-nhanh')
            ->and(pfcRedirect('/kep/kep-nhanh'))->toBe('/kep-nhanh')
            ->and(DB::table('product_taxonomies')->where('product_id', $detached->id)->count())->toBe(0);
    });
});
