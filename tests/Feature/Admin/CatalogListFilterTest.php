<?php

use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Admin\Support\Catalog\ProductListFilter;
use App\Modules\Admin\Support\Catalog\ProductListQuery;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The products list filters: category (a category and everything under it, by the main category),
 * translations, labels, stock and sale. Values inside one filter are alternatives, the filters
 * together all have to hold; the numbers beside values are the general ones; a brochure site has
 * no stock or sale filter; the last choice is remembered per member (not the sale one).
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
function filterProduct(string $name, array $attributes = [], ?Taxonomy $category = null, string $status = 'published'): Product
{
    $product = Product::query()->create($attributes + ['status' => $status]);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => 'sp-'.$product->id]);
    if ($category !== null) {
        $product->assignCategories([$category->id]);
    }

    return $product;
}

function filterCategory(string $name, ?Taxonomy $parent = null, int $position = 0): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'parent_id' => $parent?->id, 'position' => $position]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => 'c-'.$category->id]);

    return $category;
}

function filterSelling(): void
{
    SiteSetting::query()->create(['group_name' => 'catalog', 'key' => 'mode', 'value' => ['value' => 'commerce']]);
}

function filterEnglish(): void
{
    SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true]);
}

function filterManager(): User
{
    $manager = User::factory()->create();
    grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);

    return $manager->fresh();
}

/** @return list<string> the names on the page, in order */
function filterNames($test): array
{
    return collect($test->viewData('rows'))->map(fn (Product $p): string => $p->translations->firstWhere('locale', 'vi')->name)->sort()->values()->all();
}

/** @return array<string, mixed> */
function filterSection($test, string $key): array
{
    return collect($test->viewData('facets')[$key] ?? [])->keyBy('value')->all();
}

it('filters by a category and everything under it, by the main category only', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        $tools = filterCategory('Dụng cụ cắt');
        $milling = filterCategory('Dao phay', $tools);
        $drills = filterCategory('Mũi khoét', $tools, 1);
        $jigs = filterCategory('Đồ gá', position: 1);
        filterProduct('Đầu phay ngón', category: $milling);
        filterProduct('Mũi khoan thép', category: $drills);
        filterProduct('Đồ gá kẹp', category: $jigs);
        $both = filterProduct('Bộ dụng cụ', category: $jigs);
        $both->assignCategories([$jigs->id, $milling->id]); // main = Đồ gá, Dao phay second
        filterProduct('Không chuyên mục');

        $page = Livewire::actingAs($manager)->test(Index::class)->assertSet('catF', []);
        expect(filterNames($page))->toHaveCount(5);

        // ticking the parent shows its children ticked, and its subtree is listed
        $page->set('catF', [$tools->ulid]);
        expect($page->get('catF'))->toEqualCanonicalizing([$tools->ulid, $milling->ulid, $drills->ulid])
            ->and(filterNames($page))->toBe(['Mũi khoan thép', 'Đầu phay ngón']);

        // unticking one child unticks the parent too; the other child stays
        $page->set('catF', [$tools->ulid, $drills->ulid]);
        expect($page->get('catF'))->toBe([$drills->ulid])
            ->and(filterNames($page))->toBe(['Mũi khoan thép']);

        // the second category does not count: the product's main one is Đồ gá
        $page->set('catF', [$milling->ulid]);
        expect(filterNames($page))->toBe(['Đầu phay ngón']);
        $page->set('catF', [$jigs->ulid]);
        expect(filterNames($page))->toBe(['Bộ dụng cụ', 'Đồ gá kẹp']);

        // a value the screen does not know is dropped
        $page->set('catF', ['not-a-category', $jigs->ulid]);
        expect($page->get('catF'))->toBe([$jigs->ulid]);
    });
});

it('counts a category with the counter of itself and everything under it, whatever the other filters', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        $tools = filterCategory('Dụng cụ cắt');
        $milling = filterCategory('Dao phay', $tools);
        $drills = filterCategory('Mũi khoét', $tools, 1);
        filterProduct('A', ['is_featured' => true], $milling);
        filterProduct('B', category: $milling, status: 'draft');
        filterProduct('C', category: $drills);
        filterProduct('D', category: $drills)->delete(); // the Trash is not counted

        $page = Livewire::actingAs($manager)->test(Index::class);
        $counts = fn () => collect(filterSection($page, 'cat'))->map(fn (array $o) => $o['count'])->all();
        expect($counts())->toBe([$tools->ulid => 3, $milling->ulid => 2, $drills->ulid => 1]);
        expect(collect(filterSection($page, 'cat'))->map(fn (array $o) => $o['depth'])->all())->toBe([$tools->ulid => 0, $milling->ulid => 1, $drills->ulid => 1]);

        // another filter, the status group and the search leave them where they are
        $page->set('tagF', ['featured'])->set('tab', 'published')->set('search', 'A');
        expect($counts())->toBe([$tools->ulid => 3, $milling->ulid => 2, $drills->ulid => 1]);
    });
});

it('filters by label, stock and sale where products are sold, OR inside a filter and AND across filters', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        filterSelling();
        $past = now()->subDay();
        $future = now()->addDay();
        filterProduct('Nổi bật còn', ['is_featured' => true, 'stock' => 5, 'price' => 100, 'sale_price' => 80]);
        filterProduct('Bán chạy hết', ['is_bestseller' => true, 'stock' => 0, 'price' => 100, 'sale_price' => 90, 'sale_starts_at' => $past, 'sale_ends_at' => $future]);
        filterProduct('Cả hai không theo dõi', ['is_featured' => true, 'is_bestseller' => true, 'price' => 100]);
        filterProduct('Giảm giá hết hạn', ['stock' => 2, 'price' => 100, 'sale_price' => 70, 'sale_ends_at' => $past]);
        filterProduct('Giảm giá chưa tới', ['stock' => 2, 'price' => 100, 'sale_price' => 70, 'sale_starts_at' => $future]);
        filterProduct('Giá khuyến mãi cao hơn', ['stock' => 1, 'price' => 100, 'sale_price' => 120]);
        filterProduct('Trơn', ['stock' => 9]);

        $page = Livewire::actingAs($manager)->test(Index::class);

        $page->set('tagF', ['featured']);
        expect(filterNames($page))->toBe(['Cả hai không theo dõi', 'Nổi bật còn']);
        $page->set('tagF', ['featured', 'best']);
        expect(filterNames($page))->toBe(['Bán chạy hết', 'Cả hai không theo dõi', 'Nổi bật còn']);

        // stock: more than none, none left, not tracked
        $page->set('tagF', [])->set('stockF', ['out']);
        expect(filterNames($page))->toBe(['Bán chạy hết']);
        $page->set('stockF', ['none']);
        expect(filterNames($page))->toBe(['Cả hai không theo dõi']);
        $page->set('stockF', ['in', 'out']);
        expect(filterNames($page))->toHaveCount(6);

        // sale: a lower price inside its dates (an open end counts)
        $page->set('stockF', [])->set('saleF', ['on']);
        expect(filterNames($page))->toBe(['Bán chạy hết', 'Nổi bật còn']);

        // across the filters: all must hold
        $page->set('tagF', ['best'])->set('stockF', ['out']);
        expect(filterNames($page))->toBe(['Bán chạy hết']);
        $page->set('stockF', ['in']);
        expect(filterNames($page))->toBe([]);
    });
});

it('puts the same numbers beside the values whatever else is filtered', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        filterSelling();
        filterProduct('A', ['is_featured' => true, 'stock' => 3, 'price' => 100, 'sale_price' => 50]);
        filterProduct('B', ['is_bestseller' => true, 'stock' => 0]);
        filterProduct('C', ['is_featured' => true], status: 'draft');
        filterProduct('D', ['stock' => 4])->delete();

        $page = Livewire::actingAs($manager)->test(Index::class);
        $numbers = fn () => [
            collect(filterSection($page, 'tag'))->map(fn (array $o) => $o['count'])->all(),
            collect(filterSection($page, 'stock'))->map(fn (array $o) => $o['count'])->all(),
        ];
        $first = $numbers();
        expect($first)->toBe([['featured' => 2, 'best' => 1], ['in' => 1, 'out' => 1, 'none' => 1]]);

        $page->set('tagF', ['best'])->set('saleF', ['on'])->set('tab', 'published');
        expect($numbers())->toBe($first);
    });
});

it('offers the sale filter only while some product is on sale or the filter is on, with the number on sale beside it', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        filterSelling();
        filterProduct('Trơn', ['price' => 100]);

        $none = Livewire::actingAs($manager)->test(Index::class);
        expect($none->viewData('facets')['sale'])->toBe([]);

        // on by a link, with nothing on sale: still shown, so it can be turned off
        $kept = Livewire::withQueryParams(['sale' => ['on']])->actingAs($manager)->test(Index::class);
        expect($kept->viewData('facets')['sale'])->toHaveCount(1)->and(filterNames($kept))->toBe([]);

        filterProduct('Giảm', ['price' => 100, 'sale_price' => 60]);
        $offered = Livewire::actingAs($manager)->test(Index::class)->viewData('facets')['sale'];
        expect($offered)->toHaveCount(1)->and($offered[0]['count'])->toBe(1);
    });
});

it('counts each filter value on its own up to the cap, in one statement, even past the cap in products', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        filterSelling();
        foreach (range(1, 5) as $i) {
            filterProduct("Nổi bật {$i}", ['is_featured' => true, 'stock' => $i]);
        }
        foreach (range(1, 3) as $i) {
            filterProduct("Trơn {$i}");
        }
        filterProduct('Bán chạy một', ['is_bestseller' => true, 'stock' => 0]);
        filterProduct('Giảm giá một', ['is_bestseller' => true, 'price' => 100, 'sale_price' => 60]);

        $statements = 0;
        DB::listen(function () use (&$statements): void {
            $statements++;
        });
        // 10 live products, the cap is 3: a value past the cap reads cap + 1 ("hơn"), a value under it is exact
        $counts = (new ProductListQuery(1, 'all', '', 'vi', 3))->valueCounts(['vi', 'en'], true);

        expect($statements)->toBe(1)
            ->and($counts['tags'])->toBe(['featured' => 4, 'best' => 2])
            ->and($counts['stock'])->toBe(['in' => 4, 'out' => 1, 'none' => 4])
            ->and($counts['trans'])->toBe(['full' => 0, 'partial' => 0, 'none' => 4])
            ->and($counts['sale'])->toBe(1);

        // with the real cap every number is exact
        $exact = (new ProductListQuery(1, 'all', '', 'vi'))->valueCounts(['vi', 'en'], true);
        expect($exact['tags'])->toBe(['featured' => 5, 'best' => 2])->and($exact['trans']['none'])->toBe(10);
    });
});

it('has no translation filter on a site with one language and drops its values', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        filterProduct('Một');

        $one = Livewire::withQueryParams(['tr' => ['full']])->actingAs($manager)->test(Index::class);
        expect($one->viewData('facets')['trans'])->toBe([])->and($one->get('transF'))->toBe([])->and($one->viewData('filterCount'))->toBe(0);
        $one->set('transF', ['full'])->assertSet('transF', [])->assertDontSee(__('entries.f_trans'));
    });
});

it('filters by translation state only on a site with two languages, by which languages have a row', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        filterEnglish();
        $full = filterProduct('Đủ');
        $full->translations()->create(['locale' => 'en', 'name' => 'Full', 'slug' => 'full-'.$full->id]);
        filterProduct('Thiếu');

        $page = Livewire::actingAs($manager)->test(Index::class);
        expect(array_keys(filterSection($page, 'trans')))->toBe(['full', 'partial', 'none'])
            ->and(collect(filterSection($page, 'trans'))->map(fn (array $o) => $o['count'])->all())->toBe(['full' => 1, 'partial' => 0, 'none' => 1]);
        $page->set('transF', ['full']);
        expect(filterNames($page))->toBe(['Đủ']);
        $page->set('transF', ['none']);
        expect(filterNames($page))->toBe(['Thiếu']);
        $page->set('transF', ['full', 'none']);
        expect(filterNames($page))->toBe(['Thiếu', 'Đủ']);
        $page->set('transF', ['partial']);
        expect(filterNames($page))->toBe([]);
    });
});

it('leaves the stock and sale filters out of a brochure site and refuses their values', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        filterProduct('Một', ['stock' => 0, 'price' => 100, 'sale_price' => 50]);
        filterProduct('Hai', ['stock' => 5]);

        $brochure = Livewire::withQueryParams(['stock' => ['out'], 'sale' => ['on']])->actingAs($manager)->test(Index::class);
        expect($brochure->viewData('facets')['stock'])->toBe([])
            ->and($brochure->viewData('facets')['sale'])->toBe([])
            ->and($brochure->get('stockF'))->toBe([])->and($brochure->get('saleF'))->toBe([])
            ->and($brochure->viewData('filterCount'))->toBe(0)
            ->and(filterNames($brochure))->toBe(['Hai', 'Một']);
        $brochure->set('stockF', ['out'])->assertSet('stockF', [])->set('saleF', ['on'])->assertSet('saleF', []);
        $brochure->assertDontSee(__('catalog.f_stock'))->assertDontSee(__('catalog.sale_on'));

        filterSelling();
        $selling = Livewire::actingAs($manager)->test(Index::class)->assertSee(__('catalog.f_stock'));
        expect($selling->viewData('facets')['stock'])->toHaveCount(3);
    });
});

it('shows a long category list short, with its own search box, and the rest on request', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        $names = ['Dao phay', 'Mũi khoét', 'Đồ gá', 'Ép nhựa', 'Ốc vít', 'Thước cặp', 'Búa', 'Kìm', 'Cưa', 'Đục', 'Giũa', 'Mỏ lết', 'Tuốc nơ vít', 'Khoan tay', 'Máy mài', 'Bàn kẹp'];
        $categories = [];
        foreach ($names as $i => $name) {
            $categories[$name] = filterCategory($name, position: $i);
        }
        filterProduct('Có hàng', category: $categories['Búa']);
        $page = Livewire::actingAs($manager)->test(Index::class);

        $sections = fn () => collect($page->viewData('facets')['cat'])->pluck('label')->all();
        expect($sections())->toHaveCount(16);
        // 16 choices: the section is searchable and cut to its first five with "Xem thêm 11 chuyên mục"
        $page->assertSee(__('admin.list.show_more', ['n' => 11, 'what' => mb_strtolower(__('catalog.f_cat'))]))
            ->assertSee(__('admin.list.search_in', ['what' => mb_strtolower(__('catalog.f_cat'))]));
        $page->call('showMore', 'cat')->assertDontSee(__('admin.list.show_more', ['n' => 11, 'what' => mb_strtolower(__('catalog.f_cat'))]));
        expect(substr_count($page->html(), 'wire:model.live="catF"'))->toBeGreaterThanOrEqual(32); // 16 in the panel and 16 in the sheet

        // the search folds accents and case, and a chosen one stays in view
        $page->call('showMore', 'cat')->set('moreF', [])->set('qF.cat', 'dao');
        $page->assertSee('Dao phay')->assertDontSee('Máy mài');
        $page->set('catF', [$categories['Búa']->ulid])->set('qF.cat', 'dao');
        $page->assertSee('Búa');
    });
});

it('draws a chip with its icon and full name for each chosen value, and Bỏ lọc clears filters, search and group but keeps the sort', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        filterSelling();
        filterEnglish();
        $tools = filterCategory('Dụng cụ cắt');
        $milling = filterCategory('Dao phay', $tools);
        filterProduct('Đầu phay ngón', ['is_featured' => true, 'stock' => 0, 'price' => 100, 'sale_price' => 50], $milling);
        filterProduct('Khác');

        $page = Livewire::actingAs($manager)->test(Index::class)->set('sortKey', 'name');
        $page->set('catF', [$tools->ulid])->set('tagF', ['featured'])->set('stockF', ['out'])->set('saleF', ['on'])->set('transF', ['none'])->set('tab', 'published');

        // the parent's chip covers its ticked child
        $labels = collect($page->viewData('filterChips'))->pluck('label')->all();
        expect($labels)->toBe([
            __('catalog.f_cat').': Dụng cụ cắt', __('entries.f_trans').': '.__('entries.trans_none'), __('catalog.f_tag').': '.__('catalog.tag_featured'),
            __('catalog.f_stock').': '.__('catalog.stock_out'), __('catalog.f_sale').': '.__('catalog.sale_on'),
        ])->and($page->viewData('filterCount'))->toBe(5)->and($page->viewData('filterCountNarrow'))->toBe(6);
        $page->assertSee(__('catalog.chip_cat_aria', ['name' => 'Dụng cụ cắt']))->assertSee(__('catalog.chip_tag_aria', ['name' => __('catalog.tag_featured')]))
            ->assertSee(__('catalog.chip_sort_aria', ['name' => __('catalog.sort_name'), 'default' => __('catalog.sort_updated')]))
            ->assertSee(__('catalog.match_of', ['n' => 1, 'm' => 2, 'unit' => __('catalog.noun')]))->assertSeeHtml('M3.00488 6.99972L11.4502');

        // a chip's ✕ takes the value away, and a parent's takes its children with it
        $page->call('toggleFilterValue', 'cat', $tools->ulid);
        expect($page->get('catF'))->toBe([]);
        $page->call('toggleFilterValue', 'tag', 'best')->assertSet('tagF', ['featured', 'best'])->call('toggleFilterValue', 'tag', 'best');
        $page->call('toggleFilterValue', 'tag', 'nonsense')->assertSet('tagF', ['featured']);
        $page->call('resetFilterGroup', 'sale')->assertSet('saleF', []);

        $page->set('search', 'Dao')->call('clearAllFilters');
        $page->assertSet('catF', [])->assertSet('transF', [])->assertSet('tagF', [])->assertSet('stockF', [])->assertSet('saleF', [])
            ->assertSet('search', '')->assertSet('tab', 'all')->assertSet('sortKey', 'name');
        expect($page->viewData('filterChips'))->toBe([]);
    });
});

it('says how many of how many match once a filter or the search is on', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        filterProduct('Nổi', ['is_featured' => true]);
        filterProduct('Thường');
        filterProduct('Nháp', status: 'draft');

        $match = fn (int $n, int $m): string => __('catalog.match_of', ['n' => $n, 'm' => $m, 'unit' => __('catalog.noun')]);
        $page = Livewire::actingAs($manager)->test(Index::class);
        $page->set('tagF', ['featured'])->assertSee($match(1, 3));
        $page->set('tab', 'published')->assertSee($match(1, 2));
        expect($page->viewData('matchCount'))->toBe(1)->and($page->viewData('pager')['total'])->toBe(1);
    });
});

it('remembers the last filters of a member, but not the sale one, and lets a link with filters win', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        filterSelling();
        filterEnglish();
        $tools = filterCategory('Dụng cụ cắt');
        filterProduct('Nổi', ['is_featured' => true, 'price' => 100, 'sale_price' => 50, 'stock' => 0], $tools);
        filterProduct('Thường');

        Livewire::actingAs($manager)->test(Index::class)
            ->set('catF', [$tools->ulid])->set('tagF', ['featured'])->set('stockF', ['out'])->set('transF', ['none'])->set('saleF', ['on']);
        expect($manager->fresh()->preferences['lists']['catalog'])
            ->toMatchArray(['cat' => [$tools->ulid], 'tag' => ['featured'], 'stock' => ['out'], 'tr' => ['none']])
            ->and($manager->fresh()->preferences['lists']['catalog'])->not->toHaveKey('sale');

        // a visit with no filter in the link comes back to them; the sale filter does not
        $back = Livewire::actingAs($manager->fresh())->test(Index::class);
        expect($back->get('catF'))->toBe([$tools->ulid])->and($back->get('tagF'))->toBe(['featured'])
            ->and($back->get('stockF'))->toBe(['out'])->and($back->get('transF'))->toBe(['none'])->and($back->get('saleF'))->toBe([]);

        // clearing is remembered too
        $back->call('clearAllFilters');
        expect(Livewire::actingAs($manager->fresh())->test(Index::class)->get('tagF'))->toBe([]);
    });
});

it('lets a link with its own filter win over the remembered ones', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        filterProduct('Nổi', ['is_featured' => true]);
        filterProduct('Chạy', ['is_bestseller' => true]);

        Livewire::actingAs($manager)->test(Index::class)->set('tagF', ['featured']);
        $link = Livewire::withQueryParams(['tag' => ['best']])->actingAs($manager->fresh())->test(Index::class);
        expect($link->get('tagF'))->toBe(['best'])->and($link->get('catF'))->toBe([])->and(filterNames($link))->toBe(['Chạy']);
    });
});

it('refuses a filter update from a signed-out browser and from someone who is not a member, with 403', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        filterProduct('Nổi', ['is_featured' => true]);

        $page = Livewire::actingAs($manager)->test(Index::class);
        auth()->guard('web')->logout();
        app('auth')->forgetGuards();
        $page->set('tagF', ['featured'])->assertForbidden();

        $page = Livewire::actingAs($manager)->test(Index::class);
        auth()->guard('web')->logout();
        app('auth')->forgetGuards();
        $page->call('toggleFilterValue', 'tag', 'featured')->assertForbidden();

        $page = Livewire::actingAs($manager)->test(Index::class);
        auth()->guard('web')->logout();
        app('auth')->forgetGuards();
        $page->call('resetFilterGroup', 'tag')->assertForbidden();

        $stranger = User::factory()->create();
        Livewire::actingAs($stranger->fresh())->test(Index::class)->assertForbidden();
    });
});

it('keeps a cursor to the view it was made for, filters included', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $manager = filterManager();
        foreach (range(1, 4) as $i) {
            filterProduct("SP {$i}", ['is_featured' => $i % 2 === 0]);
        }
        $list = new ProductListQuery(1, 'all', '', 'vi', ProductListQuery::CAP, new ProductListFilter(tags: ['featured']));
        expect($list->rows('updated', 0, 10)->count())->toBe(2)
            ->and($list->count($list->filtered()))->toBe(2);
    });
});
