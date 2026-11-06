<?php

use App\Modules\Catalog\Components\ProductGridComponent;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Components\EntryGridComponent;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\View;

/*
 * An article grid saved before the tabs, and a product grid saved before the source and the sort, draw
 * the exact bytes they always drew. The golden files hold what the component and the template drew
 * before those props existed.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** Three published posts in one category and three published products, with fixed dates. */
function oldGridSeed(): void
{
    SiteSetting::query()->firstOrCreate(
        ['group_name' => 'permalinks', 'key' => 'products'],
        ['value' => ['pattern' => 'base', 'base' => 'san-pham']],
    );

    $cat = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
    $cat->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);

    foreach ([1, 2, 3] as $i) {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => "2026-03-0{$i} 08:00:00", 'meta' => ['image_url' => "https://cdn.example/{$i}.avif"]]);
        $entry->translations()->create(['locale' => 'vi', 'title' => "Ấn bài {$i} Ế", 'slug' => "bai-{$i}", 'status' => 'published', 'excerpt' => "Ấ tóm tắt {$i} Ế", 'body' => ['blocks' => []]]);
        $entry->assignCategories([$cat->id]);
        app(RouteSync::class)->sync($entry, 'vi', "bai-{$i}");

        $product = Product::query()->create(['status' => 'published', 'published_at' => "2026-03-0{$i} 08:00:00"]);
        $product->translations()->create(['locale' => 'vi', 'name' => "Ấn sản phẩm {$i} Ế", 'slug' => "sp-{$i}", 'summary' => "Ấ mô tả {$i} Ế"]);
        app(RouteSync::class)->syncProduct($product->id, null, 'vi', "sp-{$i}");
    }
}

/** @return array<string, array{0: string, 1: array<string, mixed>}> */
function oldGridCases(): array
{
    $entry = ['taxonomy_slug' => 'tin-tuc', 'limit' => 6, 'locale' => 'vi', 'heading' => 'Tin tức Ấ'];
    $product = ['limit' => 6, 'locale' => 'vi', 'heading' => 'Sản phẩm Ấ'];

    return [
        'entry list' => ['entry', $entry],
        'entry news cards' => ['entry', $entry + ['variant' => 'news-cards', 'view_all_label' => 'Xem tất cả', 'view_all_url' => '/tin-tuc']],
        'entry news cards display' => ['entry', $entry + ['variant' => 'news-cards', 'layout' => 'auto', 'view_all_label' => 'Xem tất cả', 'view_all_url' => '/tin-tuc']],
        'entry zigzag' => ['entry', $entry + ['variant' => 'zigzag']],
        'product grid' => ['product', $product],
        'product overlay' => ['product', $product + ['variant' => 'overlay']],
        'product grid display' => ['product', $product + ['layout' => 'auto', 'view_all_label' => 'Xem tất cả', 'view_all_url' => '/san-pham']],
    ];
}

it('draws an article or product grid saved before the new props as the exact bytes it always drew', function (string $name): void {
    app(Tenancy::class)->enter(1, function () use ($name): void {
        oldGridSeed();
        app()->setLocale('vi');
        [$kind, $props] = oldGridCases()[$name];
        $data = $kind === 'entry' ? app(EntryGridComponent::class)->data($props) : app(ProductGridComponent::class)->data($props);
        $html = View::make('theme::sections.entry_grid', $data)->render();
        $file = __DIR__.'/golden/old-grid-'.str_replace(' ', '-', $name).'.html';

        expect($html)->toBe(file_get_contents($file));
    });
})->with(array_keys(oldGridCases()));
