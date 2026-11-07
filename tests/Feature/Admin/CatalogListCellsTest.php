<?php

use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Livewire\Livewire;

/*
 * What a row of the products list draws: price (with a sale and its old price, or "Liên hệ báo giá"),
 * stock chip, label chips, main category with its full path in a title, SKU, thumbnail title, language
 * chips, the path line with its copy button, and the times in the website's own clock. A brochure
 * website has no price or stock anywhere.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/** @param  array<string, mixed>  $attributes */
function cellProduct(string $name, array $attributes = [], ?Taxonomy $category = null): Product
{
    $product = Product::query()->create($attributes + ['status' => 'published']);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => 'sp-'.$product->id]);
    if ($category !== null) {
        $product->assignCategories([$category->id]);
    }

    return $product;
}

function cellCategory(string $name, ?Taxonomy $parent = null): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'parent_id' => $parent?->id, 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => 'c-'.$category->id]);

    return $category;
}

function cellSelling(): void
{
    SiteSetting::query()->create(['group_name' => 'catalog', 'key' => 'mode', 'value' => ['value' => 'commerce']]);
}

function cellManager(): User
{
    $manager = User::factory()->create();
    grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);

    return $manager->fresh();
}

/** A price as the list prints it: dot thousands, the sign on the number's line. */
function cellMoney(int $n): string
{
    return number_format($n, 0, ',', '.')."\u{00A0}₫";
}

it('shows the price of a sale with the old price struck beside it, and the end of the sale in a title', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        cellSelling();
        cellProduct('Máy tiện', ['price' => 1000000, 'sale_price' => 800000, 'sale_ends_at' => '2099-12-31 05:00:00+00']);

        $page = Livewire::actingAs(cellManager())->test(Index::class);

        $page->assertSeeHtml('<span class="sp-pr" data-k="v">'.cellMoney(800000).'</span>')
            ->assertSeeHtml('<span class="sp-old">'.cellMoney(1000000).'</span>')
            // the website's clock (GMT+7), not UTC
            ->assertSeeHtml('title="'.e(__('catalog.price_sale_tip', ['date' => '31/12/2099 12:00', 'price' => cellMoney(1000000)])).'"')
            ->assertSee(__('catalog.price_old_card', ['price' => cellMoney(1000000)]));
    });
});

it('words a sale with no end date plainly, and shows the regular price when the sale is over or not lower', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        cellSelling();
        cellProduct('Mở', ['price' => 500000, 'sale_price' => 400000]);
        cellProduct('Hết hạn', ['price' => 700000, 'sale_price' => 600000, 'sale_ends_at' => '2020-01-01 00:00:00+00']);
        cellProduct('Không rẻ hơn', ['price' => 300000, 'sale_price' => 300000]);

        $page = Livewire::actingAs(cellManager())->test(Index::class);

        $page->assertSeeHtml('title="'.e(__('catalog.price_sale_tip_open', ['price' => cellMoney(500000)])).'"')
            ->assertSeeHtml('<span class="sp-pr" data-k="v">'.cellMoney(700000).'</span>')
            ->assertSeeHtml('<span class="sp-pr" data-k="v">'.cellMoney(300000).'</span>')
            ->assertDontSeeHtml('<span class="sp-old">'.cellMoney(700000).'</span>')
            ->assertDontSeeHtml('<span class="sp-old">'.cellMoney(300000).'</span>');
        // one old price on the whole page: only the sale that is on
        expect(substr_count($page->html(), 'class="sp-old"'))->toBe(1);
    });
});

it('asks for a quote when a product has no price', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        cellSelling();
        cellProduct('Chưa giá');

        Livewire::actingAs(cellManager())->test(Index::class)
            ->assertSeeHtml('<span class="sp-pr" data-k="ask">'.e(__('catalog.price_ask')).'</span>')
            ->assertSeeHtml('title="'.e(__('catalog.price_ask_tip')).'"');
    });
});

it('draws a stock chip of each kind: out, low, in and not tracked', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        cellSelling();
        cellProduct('Hết', ['stock' => 0]);
        cellProduct('Sắp hết', ['stock' => 5]);
        cellProduct('Còn nhiều', ['stock' => 1234]);
        cellProduct('Không đếm');

        $html = Livewire::actingAs(cellManager())->test(Index::class)->html();

        expect($html)->toContain('data-k="out"')->toContain(__('catalog.stock_out'))
            ->and($html)->toContain('data-k="low"')->toContain(__('catalog.stock_left', ['n' => '5']))
            ->and($html)->toContain('data-k="in"')->toContain(__('catalog.stock_left', ['n' => Number::format(1234)]))
            ->and($html)->toContain('data-k="none"')->toContain(__('catalog.stock_none'));
    });
});

it('draws the label chips and a dash for a product with none', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        cellProduct('Cả hai', ['is_featured' => true, 'is_bestseller' => true]);
        cellProduct('Trơn');

        $html = Livewire::actingAs(cellManager())->test(Index::class)->html();

        expect(substr_count($html, 'class="sp-tag"'))->toBe(2)
            ->and($html)->toContain('data-k="featured"')->toContain('data-k="best"')
            ->and(substr_count($html, 'class="sp-none"'))->toBe(1)
            // on the line under the name the labels are one comma list
            ->and($html)->toContain(__('catalog.tag_featured').', '.__('catalog.tag_best'));
    });
});

it('names the main category, gives its whole path in a title, and says so when there is none', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $root = cellCategory('Máy công cụ');
        $child = cellCategory('Máy tiện đứng', $root);
        cellProduct('Có chuyên mục', [], $child);
        cellProduct('Không chuyên mục');

        $html = Livewire::actingAs(cellManager())->test(Index::class)->html();

        expect($html)->toContain('title="Máy công cụ › Máy tiện đứng"')
            ->and($html)->toContain('>Máy tiện đứng<')
            ->and($html)->toContain(__('catalog.cat_none'));
    });
});

it('shows the SKU, or says there is none, and titles the thumbnail by how many pictures there are', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $withSku = cellProduct('Có mã', ['sku' => 'MT-850']);
        cellProduct('Không mã');
        foreach ([0, 1] as $i) {
            $media = Media::query()->create([
                'collection' => 'library', 'slug' => "anh-{$i}", 'hash' => hash('sha256', "img-{$i}"),
                'storage_key' => "s/library/anh-{$i}.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
            ]);
            $withSku->images()->create(['media_id' => $media->id, 'position' => $i]);
        }

        $html = Livewire::actingAs(cellManager())->test(Index::class)->html();

        expect($html)->toContain(__('catalog.sku_meta', ['sku' => 'MT-850']))
            ->and($html)->toContain(__('catalog.sku_none'))
            ->and($html)->toContain('title="'.e(__('catalog.thumb_n', ['n' => '2'])).'"')
            ->and($html)->toContain('title="'.e(__('catalog.thumb_none')).'"');
    });
});

it('shows no language chips and no missing-translation note on a website with one language', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        cellProduct('Một thứ tiếng');

        $page = Livewire::actingAs(cellManager())->test(Index::class);

        expect($page->html())->not->toContain('list-lcs')
            ->and($page->viewData('cardOptions'))->toBe(['sku', 'cat', 'slug']);
    });
});

it('shows a chip per language and the missing-translation note on a website with two languages', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true]);
        $both = cellProduct('Đủ hai thứ');
        $both->translations()->create(['locale' => 'en', 'name' => 'Both', 'slug' => 'both']);
        cellProduct('Thiếu bản dịch');

        $page = Livewire::actingAs(cellManager())->test(Index::class);
        $html = $page->html();

        expect(substr_count($html, 'class="list-lc"'))->toBe(4)
            ->and(substr_count($html, 'list-lmiss"'))->toBe(1)
            ->and($html)->toContain(__('entries.lc_missing_note', ['names' => __('entries.lang_short_en')]))
            ->and($page->viewData('cardOptions'))->toBe(['sku', 'cat', 'slug', 'tr']);
    });
});

it('leaves price and stock out of a brochure website: heads, cells, the line under the name and the column menu', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        cellProduct('Giới thiệu', ['price' => 100000, 'stock' => 3]);

        $page = Livewire::actingAs(cellManager())->test(Index::class);
        $html = $page->html();

        foreach (['list-c-price', 'list-c-stock', 'sp-pr', 'sp-stk', 'data-for="price"', 'data-for="stock"', 'value="price"', 'value="stock"'] as $mark) {
            expect($html)->not->toContain($mark);
        }
        expect($html)->toContain('list-c-badge')->toContain('list-c-cat')->toContain('value="badge"');
        expect(Livewire::actingAs(cellManager())->test(Index::class)->viewData('colOptions'))->toBe(['cat', 'badge']);
    });
});

it('shows the path as text with a copy button, and no copy button on a row in the Trash', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $live = cellProduct('Còn bán');
        $gone = cellProduct('Đã xoá');
        $gone->delete();

        $manager = cellManager();
        $html = Livewire::actingAs($manager)->test(Index::class)->html();
        expect($html)->toContain('>/sp-'.$live->id.'<')
            ->and(substr_count($html, 'class="list-cpy"'))->toBe(1)
            ->and($html)->toContain(e(__('catalog.copy_slug_aria', ['url' => 'localhost/sp-'.$live->id])));

        $trash = Livewire::actingAs($manager)->test(Index::class)->set('tab', 'trash')->html();
        expect($trash)->toContain('>/sp-'.$gone->id.'<')->not->toContain('list-cpy');
    });
});

it('prints the last edit in the website clock with the full time in a title, and the short form on a card', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $old = cellProduct('Cũ');
        Product::query()->whereKey($old->id)->toBase()->update(['updated_at' => '2025-03-04 17:30:00+00']);

        $html = Livewire::actingAs(cellManager())->test(Index::class)->html();

        expect($html)->toContain(__('catalog.updated_at', ['date' => '05/03/2025 00:30']))
            ->and($html)->toContain('title="'.e(__('catalog.updated_full', ['date' => '05/03/2025 00:30'])).'"')
            ->and($html)->toContain(__('catalog.edited_other', ['date' => '05/03/2025']));
    });
});

it('words a Trash row by the day it went to the Trash', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $gone = cellProduct('Đã xoá');
        $gone->delete();
        Product::withTrashed()->whereKey($gone->id)->toBase()->update(['deleted_at' => '2026-10-01 17:30:00+00']);

        $html = Livewire::actingAs(cellManager())->test(Index::class)->set('tab', 'trash')->html();

        expect($html)->toContain(__('catalog.trashed_at', ['date' => '02/10/2026 00:30']))
            ->and($html)->toContain('title="'.e(__('catalog.trashed_at_title', ['date' => '02/10/2026 00:30'])).'"');
    });
});

it('words a row edited today or yesterday in the short form of a card', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $today = cellProduct('Hôm nay đã sửa');
        $yesterday = cellProduct('Hôm qua đã sửa');
        $zone = app(SiteTime::class);
        Product::query()->whereKey($yesterday->id)->toBase()->update(['updated_at' => $zone->now()->subDay()->setTime(8, 15)->utc()->toDateTimeString().'+00']);

        $html = Livewire::actingAs(cellManager())->test(Index::class)->html();

        expect($html)->toContain(__('catalog.edited_yesterday', ['time' => '08:15']))
            ->and($html)->toContain(__('catalog.edited_today', ['time' => $zone->forDisplay($today->fresh()->updated_at)->format('H:i')]));
    });
});
