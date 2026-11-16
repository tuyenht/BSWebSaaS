<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Storefront\Http\Controllers\SearchSuggestController;
use App\Modules\Storefront\Search\SearchSuggestions;
use App\Modules\Storefront\Search\SiteSearch;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Theming\StorefrontSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
 * The suggestions under a search box while the visitor types (/tim-kiem/goi-y?q=…): plain JSON of at
 * most five rows (the matched categories, then product codes that start with what was typed, then
 * titles newest first), put under their group's heading, each with its address, a small picture from
 * the media key (never an id), a meta line (a code, a day) and never a description; how many results
 * the search page has, up to 51; only what a visitor of that site and language may open; its own
 * limit per visitor; never cached, never indexed; nothing at all while the site has search switched off.
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

/** A picture in the media library of the current site, by its content-hash key. */
function sgMedia(string $name): Media
{
    return Media::query()->create([
        'storage_key' => "tenant/products/{$name}-0123456789ab.jpg", 'mime' => 'image/jpeg',
        'size' => 10, 'original_filename' => "{$name}.jpg", 'hash' => str_pad($name, 64, 'a'), 'slug' => $name,
    ]);
}

/** @return array<string, mixed> */
function sgGet(string $host, string $typed, string $prefix = ''): array
{
    $response = test()->get("http://{$host}{$prefix}/tim-kiem/goi-y?q=".rawurlencode($typed))->assertOk();

    return $response->json();
}

it('finds “Đồ gá” from “do ga”, as JSON rows of label, matched pieces, group, address and meta line, kept a minute at the edge, never indexed', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $news = sgCategory('Tin tức', listTemplate: 'grid-news');
        sgPost('5 lưu ý khi đặt gia công đồ gá theo bản vẽ', [$news->id], at: CarbonImmutable::parse('2026-10-11 20:30:00', 'UTC'));
    });

    $response = $this->get('http://a.test/tim-kiem/goi-y?q=do%20ga')->assertOk();
    $item = $response->json('items.0');

    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toBe('max-age=0, public, s-maxage=60, stale-while-revalidate=60')
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex')
        ->and($response->json('q'))->toBe('do ga')
        ->and($response->json('all_url'))->toBe('/tim-kiem?q=do+ga')
        ->and($response->json('items'))->toHaveCount(1)
        ->and($item['kind'])->toBe('item')
        ->and($item['label'])->toBe('5 lưu ý khi đặt gia công đồ gá theo bản vẽ')
        ->and($item['parts'])->toBe([['5 lưu ý khi đặt gia công ', false], ['đồ gá', true], [' theo bản vẽ', false]])
        ->and($item['group'])->toBe('Tin tức')
        ->and($item['group_key'])->toStartWith('c:tin-tuc-')
        ->and($item['icon'])->toBe('doc')
        ->and($item['url'])->toMatch('#^/bai-\d+$#')
        // a news post's day, in the site's zone (GMT+7: the 12th)
        ->and($item['meta'])->toBe('12/10/2026')
        // no picture: the box draws a tile; never a description
        ->and($item['thumb'])->toBeNull()
        ->and($item)->not->toHaveKey('text')->not->toHaveKey('image')
        ->and($response->json('total'))->toBe(1)
        ->and($response->json('total_more'))->toBeFalse();
});

it('puts the matched categories first under «Nhóm», then the titles newest first under the group the results page uses', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        sgCategory('Jig & Đồ gá', 'product_cat');
        $projects = sgCategory('Dự án', listTemplate: 'grid-project');
        sgPost('Hệ thống đồ gá hàn robot', [$projects->id], at: now()->subHours(2));
        sgProduct('Đồ gá kiểm tra lỗ định vị', at: now()->subHour());
        sgPost('Trang đồ gá', type: 'page', at: now()->subHours(3));
    });

    $items = sgGet('a.test', 'do ga')['items'];

    expect(array_column($items, 'kind'))->toBe(['group', 'item', 'item', 'item'])
        // the heading says «Nhóm»; the row is the category's own name, never «Nhóm: …»
        ->and($items[0]['label'])->toBe('Jig & Đồ gá')
        ->and($items[0]['parts'])->toBe([['Jig & ', false], ['Đồ gá', true]])
        ->and($items[0]['group'])->toBe('Nhóm')
        ->and($items[0]['group_key'])->toBe('nhom')
        ->and($items[0]['icon'])->toBe('folder')
        ->and($items[0]['url'])->toMatch('#^/jig-do-ga-\d+$#')
        ->and(array_column($items, 'label'))->toBe(['Jig & Đồ gá', 'Đồ gá kiểm tra lỗ định vị', 'Hệ thống đồ gá hàn robot', 'Trang đồ gá'])
        ->and(array_column($items, 'group'))->toBe(['Nhóm', 'Sản phẩm', 'Dự án', 'Khác'])
        ->and(array_column($items, 'icon'))->toBe(['folder', 'box', 'briefcase', 'doc'])
        // the kind of a category, no code for a product without one, no day for a project or a page
        ->and(array_column($items, 'meta'))->toBe(['Sản phẩm', '', '', '']);
});

it('says a post category holds posts and leaves out a category with no page in the language', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        sgCategory('Khuôn mẫu');
        sgCategory('Khuôn ẩn', route: false);
    });

    $items = sgGet('a.test', 'khuon')['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['label'])->toBe('Khuôn mẫu')
        ->and($items[0]['group'])->toBe('Nhóm')
        ->and($items[0]['meta'])->toBe('Bài viết');
});

it('shows a product whose code starts with what was typed before every title, its name as the row and «Mã …» under it, the code part in bold', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        sgProduct('Đồ gá hàn khung thép 4 điểm kẹp', 'AV-DG-01', at: now()->subHours(2));
        sgProduct('Đồ gá kiểm tra lỗ định vị', 'AV-DG-02', at: now()->subHours(3));
        // the code holds the letters in its middle only: a title row, never a code row
        sgProduct('Bộ kẹp', 'X-AVDG-9', at: now()->subMinute());
        sgPost('Tin AV DG mới', at: now()->subMinute());
    });

    $items = sgGet('a.test', 'AV-DG')['items'];

    expect(array_column($items, 'kind'))->toBe(['sku', 'sku', 'item'])
        ->and($items[0]['label'])->toBe('Đồ gá hàn khung thép 4 điểm kẹp')
        ->and($items[0]['sku'])->toBe('AV-DG-01')
        ->and($items[0]['meta'])->toBe('Mã AV-DG-01')
        ->and($items[0]['meta_parts'])->toBe([['Mã ', false], ['AV-DG', true], ['-01', false]])
        ->and($items[0]['group'])->toBe('Sản phẩm')
        ->and($items[0]['icon'])->toBe('box')
        ->and($items[0]['url'])->toMatch('#^/sp-\d+$#')
        ->and($items[1]['label'])->toBe('Đồ gá kiểm tra lỗ định vị')
        ->and($items[2]['label'])->toBe('Tin AV DG mới');

    // typed without its dash: the start of a code is a code row, its middle a plain row after them
    $joined = sgGet('a.test', 'avdg')['items'];
    expect(array_column($joined, 'kind'))->toBe(['sku', 'sku', 'item'])
        ->and($joined[2]['label'])->toBe('Bộ kẹp')
        ->and($joined[2]['group'])->toBe('Sản phẩm')
        ->and(sgGet('a.test', 'avdg0')['items'][0]['meta_parts'][1])->toBe(['AV-DG-0', true]);
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

it('keeps the same items out when the words are too common to gather and the newest items are walked', function (): void {
    $walked = [];
    app(Tenancy::class)->enter(1, function (): void {
        // one draft post and one draft product carrying more words that start with “khuon” than the gather cap:
        // the rarest word is too common, so the newest visible items are walked instead
        $common = sgPost('Khuôn nhiều từ', attributes: ['status' => 'draft']);
        $commonProduct = sgProduct('Khuôn nhiều từ sp', attributes: ['status' => 'draft']);
        foreach (['entry_search_words' => ['entry_id', $common->id], 'product_site_words' => ['product_id', $commonProduct->id]] as $table => [$column, $id]) {
            DB::table($table)->insert(array_map(fn (int $n): array => ['site_id' => 1, 'locale' => 'vi', 'word' => 'khuon'.$n, $column => $id, 'weight' => 3], range(1, SiteSearch::GATHER + 1)));
        }
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
    DB::listen(function (QueryExecuted $query) use (&$walked): void {
        $walked[] = $query->sql;
    });

    expect(array_column(sgGet('a.test', 'khuon')['items'], 'label'))->toEqualCanonicalizing(['Khuôn hiện', 'Khuôn bán']);
    // both kinds went through the walk (the newest visible items), not through the word rows
    $sql = implode("\n", $walked);
    expect($sql)->toContain("e.type = 'post' and e.deleted_at is null")
        ->toContain('from products p where p.site_id = ? and p.status = \'published\'');
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

it('gives a signed-in visitor the same answer as everyone, with no session started and no cookie, so the edge may keep it', function (): void {
    app(Tenancy::class)->enter(1, fn () => sgPost('Khuôn mẫu'));

    $response = $this->withCookie((string) config('session.cookie'), str_repeat('a1', 20))->get('http://a.test/tim-kiem/goi-y?q=khuon')->assertOk();

    expect(array_column($response->json('items'), 'label'))->toBe(['Khuôn mẫu'])
        ->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('s-maxage=60')
        ->and($response->headers->getCookies())->toBe([]);
});

it('keeps an answer private when its address has a key besides the typed words, and every refusal or redirect too', function (): void {
    app(Tenancy::class)->enter(1, fn () => sgPost('Khuôn mẫu'));

    foreach (['/tim-kiem/goi-y?q=khuon&page=2', '/tim-kiem/goi-y?q=khuon&utm_source=x', '/vi/tim-kiem/goi-y?q=khuon', '/tv/tim-kiem/goi-y?q=khuon'] as $address) {
        expect($this->get('http://a.test'.$address)->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
    expect($this->get('http://a.test/tim-kiem/goi-y?q=k')->headers->get('Cache-Control'))->toContain('public');
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

it('answers {} with a 404 under a prefix that is not a public language of the site, never a page', function (string $prefix): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => false, 'created_at' => now(), 'updated_at' => now()]);
    app(Tenancy::class)->enter(1, fn () => sgPost('Khuôn mẫu'));

    $response = $this->get("http://a.test{$prefix}/tim-kiem/goi-y?q=khuon");

    $response->assertNotFound();
    expect($response->getContent())->toBe('{}')
        ->and($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex');
})->with(['not a language' => '/tv', 'a language not public' => '/en']);

it('is not there while the site has search switched off', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => ['search' => false]]);
        sgPost('Khuôn mẫu');
    });
    app(StorefrontSettings::class)->forget();

    $off = $this->get('http://a.test/tim-kiem/goi-y?q=khuon')->assertNotFound();
    expect($off->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and(sgGet('b.test', 'khuon')['items'])->toBe([]);
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
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
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
        ->toContain('&quot;none_title&quot;:&quot;Chưa có gợi ý cho “:q”&quot;');

    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => ['search' => false]]));
    app(StorefrontSettings::class)->forget();

    expect($this->get('http://a.test/khong-co')->getContent())->not->toContain('data-suggest');
});

it('keeps the rows of a group together under its heading, the groups in the order their first row came', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $news = sgCategory('Tin tức', listTemplate: 'grid-news');
        sgPost('Khuôn tin mới', [$news->id], at: now()->subMinutes(1));
        sgProduct('Khuôn sản phẩm', at: now()->subMinutes(2));
        sgPost('Khuôn tin cũ', [$news->id], at: now()->subMinutes(3));
        sgPost('Khuôn khác', at: now()->subMinutes(4));
    });

    $items = sgGet('a.test', 'khuon')['items'];

    expect(array_column($items, 'label'))->toBe(['Khuôn tin mới', 'Khuôn tin cũ', 'Khuôn sản phẩm', 'Khuôn khác'])
        ->and(array_column($items, 'group'))->toBe(['Tin tức', 'Tin tức', 'Sản phẩm', 'Khác'])
        ->and($items[3]['group_key'])->toBe(SiteSearch::OTHER)
        ->and($items[2]['group_key'])->toBe(SiteSearch::PRODUCTS);
});

it('gives each row its picture from the media key at the small width, a product’s first live photo, never an id', function (): void {
    $keys = app(Tenancy::class)->enter(1, function (): array {
        $product = sgProduct('Khuôn có ảnh');
        $trashed = sgMedia('khuon-xoa');
        $product->images()->create(['media_id' => $trashed->id, 'position' => 0]);
        $trashed->delete();
        $first = sgMedia('khuon-dau');
        $product->images()->create(['media_id' => $first->id, 'position' => 1]);
        $product->images()->create(['media_id' => sgMedia('khuon-sau')->id, 'position' => 2]);
        sgPost('Khuôn bài có ảnh', attributes: ['meta' => ['image_url' => 'tenant/entries/bia-abcdef012345.jpg']], at: now()->subMinutes(5));
        sgPost('Khuôn bài ảnh cũ', attributes: ['meta' => ['image_url' => 'https://img.example.test/cu.jpg']], at: now()->subMinutes(6));
        sgPost('Khuôn bài ảnh http', attributes: ['meta' => ['image_url' => 'http://img.example.test/cu.jpg']], at: now()->subMinutes(8));
        sgPost('Khuôn bài ảnh lạ', attributes: ['meta' => ['image_url' => 'javascript:alert(1)']], at: now()->subMinutes(7));

        return ['product' => $product->id, 'media' => $first->id];
    });

    $items = collect(sgGet('a.test', 'khuon')['items'])->keyBy('label');
    $urls = app(MediaUrlResolver::class);

    expect($items['Khuôn có ảnh']['thumb'])->toBe($urls->url('tenant/products/khuon-dau-0123456789ab.jpg', SearchSuggestions::THUMB_WIDTH))
        ->and($items['Khuôn bài có ảnh']['thumb'])->toBe($urls->url('tenant/entries/bia-abcdef012345.jpg', SearchSuggestions::THUMB_WIDTH))
        // a cover saved as a full https address before keys were stored: as it is; anything else: none
        ->and($items['Khuôn bài ảnh cũ']['thumb'])->toBe('https://img.example.test/cu.jpg')
        // a plain http address would be mixed content on an https page: none
        ->and($items['Khuôn bài ảnh http']['thumb'])->toBeNull()
        ->and($items['Khuôn bài ảnh lạ']['thumb'])->toBeNull();
    // the media key is the picture's address: neither the product's nor the picture's number is in it
    foreach ($items as $item) {
        expect((string) $item['thumb'])->not->toMatch('#[/=-]('.$keys['product'].'|'.$keys['media'].')(\D|$)#');
    }
});

it('reads the pictures in the same reads as the rows: the query count does not grow with them', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        foreach (range(1, 3) as $n) {
            $product = sgProduct("Khuôn {$n}");
            $product->images()->create(['media_id' => sgMedia("anh-{$n}")->id, 'position' => 0]);
        }
    });
    app(Tenancy::class)->enter(2, fn () => sgProduct('Khuôn trơn'));

    $count = function (string $host): int {
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        sgGet($host, 'khuon');

        return $n;
    };

    expect($count('a.test'))->toBe($count('b.test'));
});

it('counts the search page’s results for «Xem tất cả N kết quả», stopping at 51, with the same rules', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        foreach (range(1, 30) as $n) {
            sgPost("Khuôn bài {$n}", at: now()->subMinutes($n));
        }
        foreach (range(1, 25) as $n) {
            sgProduct("Khuôn sp {$n}", at: now()->subMinutes($n));
        }
        // what the search page leaves out is not counted
        sgPost('Khuôn nháp', attributes: ['status' => 'draft']);
        sgProduct('Khuôn nháp sp', attributes: ['status' => 'draft']);
        sgPost('Jig hàn 1');
        sgPost('Jig hàn 2');
        sgPost('Jig hàn nháp', attributes: ['status' => 'draft']);
    });
    app(Tenancy::class)->enter(2, function (): void {
        foreach (range(1, 3) as $n) {
            sgPost("Jig hàn B {$n}");
        }
    });

    $many = sgGet('a.test', 'khuon');
    $few = sgGet('a.test', 'jig han');

    expect($many['total'])->toBe(SearchSuggestions::COUNT_CAP)
        ->and($many['total_more'])->toBeFalse()
        // another site's items never count
        ->and($few['total'])->toBe(2)
        ->and(sgGet('b.test', 'jig han')['total'])->toBe(3)
        // nothing found: no count asked
        ->and(sgGet('a.test', 'zzqq')['total'])->toBeNull();
});

it('says the count is a floor when the words are too common and the newest items are walked', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $common = sgPost('Khuôn nhiều từ', attributes: ['status' => 'draft']);
        DB::table('entry_search_words')->insert(array_map(fn (int $n): array => ['site_id' => 1, 'locale' => 'vi', 'word' => 'khuon'.$n, 'entry_id' => $common->id, 'weight' => 3], range(1, SiteSearch::GATHER + 1)));
        sgPost('Khuôn hiện');
    });

    $json = sgGet('a.test', 'khuon');

    expect($json['total'])->toBe(1)
        ->and($json['total_more'])->toBeTrue();
});
