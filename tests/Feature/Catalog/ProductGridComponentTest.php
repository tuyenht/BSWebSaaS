<?php

use App\Modules\Catalog\Components\ProductGridComponent;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\BlockRenderer;

/*
 * ProductGridComponent — a data-bound grid of published catalog products for the home page. Cards
 * pull from the products table (RLS-scoped), link to the stored product route, and exclude drafts.
 * Registered as the `product_grid` block type into the shared Section Library.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** Pin the catalog to base=san-pham + publish a product routed at /san-pham/{slug}. */
function publishGridProduct(string $slug, string $name, string $status = 'published', ?string $summary = null): Product
{
    SiteSetting::query()->firstOrCreate(
        ['group_name' => 'permalinks', 'key' => 'products'],
        ['value' => ['pattern' => 'base', 'base' => 'san-pham']],
    );

    $product = Product::query()->create(['status' => $status, 'published_at' => $status === 'published' ? now() : null]);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug, 'summary' => $summary]);
    app(RouteSync::class)->syncProduct($product->id, null, 'vi', $slug);

    return $product;
}

it('maps published product cards (title, stored-route url, summary) and excludes drafts', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        publishGridProduct('truc-cnc', 'Trục CNC', 'published', 'Gia công chính xác');
        publishGridProduct('nhap', 'Nháp', 'draft');

        $data = app(ProductGridComponent::class)->data(['limit' => 6, 'locale' => 'vi']);

        expect($data['items'])->toHaveCount(1)                        // draft excluded
            ->and($data['items'][0])->toMatchArray([
                'title' => 'Trục CNC',
                'url' => '/san-pham/truc-cnc',                         // the stored product route
                'short_outcome' => 'Gia công chính xác',              // from translation.summary
            ]);
    });
});

it('renders as a product_grid block through the shared registry', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        publishGridProduct('truc-cnc', 'Trục CNC');

        $html = app(BlockRenderer::class)->render(['blocks' => [
            ['type' => 'product_grid', 'props' => ['heading' => 'Sản phẩm', 'limit' => 6]],
        ]]);

        expect($html)->toContain('Trục CNC')->toContain('href="/san-pham/truc-cnc"');
    });
});
