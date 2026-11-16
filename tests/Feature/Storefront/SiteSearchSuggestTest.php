<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Storefront\Http\Controllers\SearchSuggestController;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Theming\StorefrontSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
 * The suggestions under a search box while the visitor types (/tim-kiem/goi-y?q=…): plain JSON of at
 * most five rows (the matched categories, then product codes that start with what was typed, then
 * titles newest first), each with its group and its address, never a description or a picture; only
 * what a visitor of that site and language may open; its own limit per visitor; never cached, never
 * indexed; nothing at all while the site has search switched off.
 */

beforeEach(function (): void {
    seedTenancy();
    RateLimiter::clear('site-suggest:1|127.0.0.1');
    RateLimiter::clear('site-suggest:2|127.0.0.1');
});

/** A published post (or page) of the current site with its address, filed under $categories (the first is its main one). */
function sgPost(string $title, array $categories = [], array $attributes = [], string $locale = 'vi', ?CarbonInterface $at = null, string $type = 'post', string $translationStatus = 'published'): Entry
{
    $entry = Entry::query()->create($attributes + ['type' => $type, 'status' => 'published', 'published_at' => $at ?? now()->subMinute(), 'meta' => []]);
    $entry->translations()->create(['locale' => $locale, 'title' => $title, 'slug' => 'bai-'.$entry->id, 'status' => $translationStatus]);
    SiteRoute::query()->create(['locale' => $locale, 'path' => '/bai-'.$entry->id, 'entry_id' => $entry->id]);
    if ($categories !== []) {
        $entry->assignCategories($categories);
    }

    return $entry;
}

/** A published product of the current site with its address. */
function sgProduct(string $name, ?string $sku = null, array $attributes = [], ?CarbonInterface $at = null, string $locale = 'vi'): Product
{
    $product = Product::query()->create($attributes + ['status' => 'published', 'sku' => $sku, 'published_at' => $at ?? now()->subMinute()]);
    $product->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => 'sp-'.$product->id, 'summary' => 'Mô tả đồ gá không bao giờ hiện']);
    SiteRoute::query()->create(['locale' => $locale, 'path' => '/sp-'.$product->id, 'product_id' => $product->id]);

    return $product;
}

/** A category of the current site with its address (its page). */
function sgCategory(string $name, string $vocabulary = 'post_cat', ?string $listTemplate = null, bool $route = true): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => $vocabulary, 'parent_id' => null, 'position' => 0, 'list_template' => $listTemplate]);
    $slug = Str::slug($name).'-'.$category->id;
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);
    app(TaxonomyPaths::class)->refreshBranch($category->id);
    if ($route) {
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/'.$slug, 'taxonomy_id' => $category->id]);
    }

    return $category->refresh();
}

/** @return array<string, mixed> */
function sgGet(string $host, string $typed, string $prefix = ''): array
{
    $response = test()->get("http://{$host}{$prefix}/tim-kiem/goi-y?q=".rawurlencode($typed))->assertOk();

    return $response->json();
}

it('finds “Đồ gá” from “do ga”, as JSON rows of label, matched pieces, group and address, never cached or indexed', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $news = sgCategory('Tin tức', listTemplate: 'grid-news');
        sgPost('5 lưu ý khi đặt gia công đồ gá theo bản vẽ', [$news->id]);
    });

    $response = $this->get('http://a.test/tim-kiem/goi-y?q=do%20ga')->assertOk();
    $item = $response->json('items.0');

    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex')
        ->and($response->json('q'))->toBe('do ga')
        ->and($response->json('all_url'))->toBe('/tim-kiem?q=do+ga')
        ->and($response->json('items'))->toHaveCount(1)
        ->and($item['kind'])->toBe('item')
        ->and($item['label'])->toBe('5 lưu ý khi đặt gia công đồ gá theo bản vẽ')
        ->and($item['parts'])->toBe([['5 lưu ý khi đặt gia công ', false], ['đồ gá', true], [' theo bản vẽ', false]])
        ->and($item['group'])->toBe('Tin tức')
        ->and($item['icon'])->toBe('doc')
        ->and($item['url'])->toMatch('#^/bai-\d+$#')
        // never a description or a picture
        ->and($item)->not->toHaveKey('text')->not->toHaveKey('image');
});

it('puts the matched categories first, then the titles newest first, each with the group the results page uses', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        sgCategory('Jig & Đồ gá', 'product_cat');
        $projects = sgCategory('Dự án', listTemplate: 'grid-project');
        sgPost('Hệ thống đồ gá hàn robot', [$projects->id], at: now()->subHours(2));
        sgProduct('Đồ gá kiểm tra lỗ định vị', at: now()->subHour());
        sgPost('Trang đồ gá', type: 'page', at: now()->subHours(3));
    });

    $items = sgGet('a.test', 'do ga')['items'];

    expect(array_column($items, 'kind'))->toBe(['group', 'item', 'item', 'item'])
        ->and($items[0]['label'])->toBe('Nhóm: Jig & Đồ gá')
        ->and($items[0]['parts'])->toBe([['Nhóm: Jig & ', false], ['Đồ gá', true]])
        ->and($items[0]['group'])->toBe('Nhóm sản phẩm')
        ->and($items[0]['icon'])->toBe('folder')
        ->and($items[0]['url'])->toMatch('#^/jig-do-ga-\d+$#')
        ->and(array_column($items, 'label'))->toBe(['Nhóm: Jig & Đồ gá', 'Đồ gá kiểm tra lỗ định vị', 'Hệ thống đồ gá hàn robot', 'Trang đồ gá'])
        ->and(array_column($items, 'group'))->toBe(['Nhóm sản phẩm', 'Sản phẩm', 'Dự án', 'Khác'])
        ->and(array_column($items, 'icon'))->toBe(['folder', 'box', 'briefcase', 'doc']);
});

it('names a post category as a “Chuyên mục” and leaves out a category with no page in the language', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        sgCategory('Khuôn mẫu');
        sgCategory('Khuôn ẩn', route: false);
    });

    $items = sgGet('a.test', 'khuon')['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['label'])->toBe('Nhóm: Khuôn mẫu')
        ->and($items[0]['group'])->toBe('Chuyên mục');
});

it('shows a product code that starts with what was typed as “Mã: … · name”, before every title, the code part in bold', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        sgProduct('Đồ gá hàn khung thép 4 điểm kẹp', 'AV-DG-01', at: now()->subHours(2));
        sgProduct('Đồ gá kiểm tra lỗ định vị', 'AV-DG-02', at: now()->subHours(3));
        // the code holds the letters in its middle only: a title row, never a code row
        sgProduct('Bộ kẹp', 'X-AVDG-9', at: now()->subMinute());
        sgPost('Tin AV DG mới', at: now()->subMinute());
    });

    $items = sgGet('a.test', 'AV-DG')['items'];

    expect(array_column($items, 'kind'))->toBe(['sku', 'sku', 'item'])
        ->and($items[0]['label'])->toBe('Mã: AV-DG-01 · Đồ gá hàn khung thép 4 điểm kẹp')
        ->and($items[0]['sku'])->toBe('AV-DG-01')
        ->and($items[0]['parts'])->toBe([['Mã: ', false], ['AV-DG', true], ['-01 · Đồ gá hàn khung thép 4 điểm kẹp', false]])
        ->and($items[0]['group'])->toBe('Sản phẩm')
        ->and($items[0]['icon'])->toBe('tag')
        ->and($items[0]['url'])->toMatch('#^/sp-\d+$#')
        ->and($items[1]['label'])->toBe('Mã: AV-DG-02 · Đồ gá kiểm tra lỗ định vị')
        ->and($items[2]['label'])->toBe('Tin AV DG mới');

    // typed without its dash: the start of a code is a code row, its middle a plain row after them
    $joined = sgGet('a.test', 'avdg')['items'];
    expect(array_column($joined, 'kind'))->toBe(['sku', 'sku', 'item'])
        ->and($joined[2]['label'])->toBe('Bộ kẹp')
        ->and($joined[2]['group'])->toBe('Sản phẩm')
        ->and(sgGet('a.test', 'avdg0')['items'][0]['parts'][1])->toBe(['AV-DG-0', true]);
});

it('shows at most five rows, the newest first', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        foreach (range(1, 8) as $n) {
            sgPost("Khuôn số {$n}", at: now()->subMinutes(10 - $n));
        }
    });

    $items = sgGet('a.test', 'khuon')['items'];

    expect($items)->toHaveCount(5)
        ->and(array_column($items, 'label'))->toBe(['Khuôn số 8', 'Khuôn số 7', 'Khuôn số 6', 'Khuôn số 5', 'Khuôn số 4']);
});

it('matches titles and names only, never a short description', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()->subMinute(), 'meta' => []]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Bài khác', 'slug' => 'bai-khac', 'status' => 'published', 'excerpt' => 'Nói về khuôn mẫu']);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/bai-khac', 'entry_id' => $entry->id]);
        sgProduct('Bàn kẹp');   // its summary speaks of đồ gá
    });

    expect(sgGet('a.test', 'khuon')['items'])->toBe([])
        ->and(sgGet('a.test', 'do ga')['items'])->toBe([]);
});

it('suggests only what a visitor may open: no draft, trash, scheduled, untranslated or thank-you page', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        sgPost('Khuôn hiện');
        sgPost('Khuôn nháp', attributes: ['status' => 'draft']);
        sgPost('Khuôn hẹn giờ', attributes: ['status' => 'scheduled'], at: now()->addDay());
        sgPost('Khuôn tương lai', at: now()->addDay());
        sgPost('Khuôn dịch nháp', translationStatus: 'draft');
        sgPost('Khuôn english', locale: 'en');
        sgPost('Khuôn thùng rác')->delete();
        $thanks = sgPost('Khuôn cảm ơn', type: 'page');
        DB::table('forms')->insert(['site_id' => 1, 'ulid' => (string) Str::ulid(), 'key' => 'lien-he', 'name' => json_encode(['vi' => 'Liên hệ']), 'settings' => json_encode(['redirect_entry' => $thanks->ulid]), 'created_at' => now(), 'updated_at' => now()]);
        sgProduct('Khuôn bán');
        sgProduct('Khuôn nháp sp', attributes: ['status' => 'draft']);
        sgProduct('Khuôn hẹn sp', at: now()->addDay());
        sgProduct('Khuôn english sp', locale: 'en');
        sgProduct('Khuôn xoá sp')->delete();
    });

    expect(array_column(sgGet('a.test', 'khuon')['items'], 'label'))->toEqualCanonicalizing(['Khuôn hiện', 'Khuôn bán']);
});

it('never shows one site’s items to another, even for the same words', function (): void {
    app(Tenancy::class)->enter(1, fn () => sgPost('Khuôn của A'));
    app(Tenancy::class)->enter(2, function (): void {
        sgPost('Khuôn của B');
        sgProduct('Khuôn sản phẩm B', 'KB-01');
        sgCategory('Khuôn nhóm B');
    });

    expect(array_column(sgGet('a.test', 'khuon')['items'], 'label'))->toBe(['Khuôn của A'])
        ->and(array_column(sgGet('a.test', 'KB-0')['items'], 'label'))->toBe([])
        ->and(array_column(sgGet('b.test', 'khuon')['items'], 'label'))->toContain('Khuôn của B')->not->toContain('Khuôn của A');
});

it('answers in the page’s language under its prefix, and sends the default language’s prefix to the plain address', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(Tenancy::class)->enter(1, function (): void {
        sgPost('Mould making', locale: 'en');
        sgPost('Khuôn mẫu');
    });

    $en = sgGet('a.test', 'mould', '/en');
    expect(array_column($en['items'], 'label'))->toBe(['Mould making'])
        ->and($en['all_url'])->toBe('/en/tim-kiem?q=mould')
        ->and($en['items'][0]['url'])->toStartWith('/en/')
        ->and(sgGet('a.test', 'mould')['items'])->toBe([]);

    $this->get('http://a.test/vi/tim-kiem/goi-y?q=khuon')->assertRedirect('/tim-kiem/goi-y?q=khuon')->assertStatus(301);
});

it('is not there while the site has search switched off', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => ['search' => false]]);
        sgPost('Khuôn mẫu');
    });
    app(StorefrontSettings::class)->forget();

    $this->get('http://a.test/tim-kiem/goi-y?q=khuon')->assertNotFound();
    expect(sgGet('b.test', 'khuon')['items'])->toBe([]);
});

it('gives no rows for nothing or one letter typed, without counting it against the visitor', function (string $typed): void {
    app(Tenancy::class)->enter(1, fn () => sgPost('Khuôn mẫu'));

    $json = sgGet('a.test', $typed);

    expect($json['items'])->toBe([])
        ->and(RateLimiter::attempts('site-suggest:1|127.0.0.1'))->toBe(0);
})->with(['nothing' => '', 'one letter' => 'k', 'spaces' => '   ']);

it('limits each visitor of a site on its own counter and answers {} with a 429 past it', function (): void {
    app(Tenancy::class)->enter(1, fn () => sgPost('Khuôn mẫu'));
    foreach (range(1, SearchSuggestController::MAX_PER_MINUTE) as $ignored) {
        RateLimiter::hit('site-suggest:1|127.0.0.1', 60);
    }

    $response = $this->get('http://a.test/tim-kiem/goi-y?q=khuon');

    $response->assertStatus(429);
    expect($response->getContent())->toBe('{}')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        // the results page keeps its own counter, and the other site its own
        ->and(RateLimiter::attempts('site-search:1|127.0.0.1'))->toBe(0)
        ->and(SearchSuggestController::MAX_PER_MINUTE)->toBe(120);
    expect(sgGet('b.test', 'khuon'))->toHaveKey('items');
});

it('keeps the typed words out of the counter’s key', function (): void {
    app(Tenancy::class)->enter(1, fn () => sgPost('Khuôn mẫu'));

    sgGet('a.test', 'khuon mau');

    expect(RateLimiter::attempts('site-suggest:1|127.0.0.1'))->toBe(1);
});

it('gives the search boxes where to ask, in the page’s language, and nothing while search is off', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => ['top_bar' => ['items' => []]]]);
        SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => 'Xưởng Mẫu', 'phone' => '0901 234 567']]);
        $menu = Menu::query()->create(['name' => 'Chính', 'handle' => 'primary']);
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '/san-pham', 'position' => 0]);
        $item->translations()->create(['locale' => 'vi', 'label' => 'Sản phẩm']);
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id]);
    });

    $html = $this->get('http://a.test/tim-kiem?q=khuon')->assertOk()->getContent();
    // the header panel's box and the results page's own box
    expect(substr_count($html, 'data-suggest="/tim-kiem/goi-y" data-suggest-js="'))->toBe(2)
        ->and($html)->toContain('id="sf-search-quick" data-search-quick>')
        ->toContain('aria-labelledby="sf-search-quick" data-search-quick>')
        // the box's texts ride on the form, escaped as an attribute
        ->toContain('&quot;none&quot;:&quot;Không có gợi ý&quot;');

    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => ['search' => false]]));
    app(StorefrontSettings::class)->forget();

    expect($this->get('http://a.test/khong-co')->getContent())->not->toContain('data-suggest');
});
