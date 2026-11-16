<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Storefront\Http\Controllers\SearchController;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\StorefrontChrome;
use App\Modules\Theme\Rendering\BlockStyles;
use App\Modules\Theme\Theming\StorefrontSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
 * The site search page (/tim-kiem?q=…) and the header's search button: a page kept out of search
 * engines and never cached, in the site's own header and footer; the results in groups with counts,
 * the matched words in bold made only of escaped text, "Xem thêm" as a plain link; the help when
 * nothing is found (with the site's main contact action only when it has one); a limit per visitor;
 * the address in every public language; the switch that turns all of it off.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1, 'Ánh Kim Hà');
    seedHomepage(2, 'Ước Mơ Sứ');
    RateLimiter::clear('site-search:1|127.0.0.1');
});

/** Give a site the rich header (its setting), a phone number and a main menu: home, then the links. */
function spSite(int $siteId, array $header = [], array $links = ['Sản phẩm' => '/san-pham', 'Tin tức' => '/tin-tuc'], bool $ctaItem = false): void
{
    app(Tenancy::class)->enter($siteId, function () use ($header, $links, $ctaItem): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => $header + ['top_bar' => ['items' => []]]]);
        SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => ['name' => 'Xưởng Mẫu', 'phone' => '0901 234 567']]);
        $menu = Menu::query()->create(['name' => 'Chính', 'handle' => 'primary']);
        $position = 0;
        foreach ($links as $label => $url) {
            $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => $url, 'position' => $position++]);
            $item->translations()->create(['locale' => 'vi', 'label' => $label]);
        }
        if ($ctaItem) {
            $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '/bao-gia', 'position' => $position, 'meta' => ['cta' => true]]);
            $item->translations()->create(['locale' => 'vi', 'label' => 'Gửi yêu cầu báo giá']);
        }
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id]);
    });
    app(StorefrontSettings::class)->forget();
}

/** A published post of the current site at /{slug}, filed under $categories (the first is its main one). */
function spPost(string $title, ?string $excerpt = null, array $categories = [], ?string $slug = null, string $locale = 'vi', ?CarbonInterface $at = null): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => $at ?? now()->subMinute(), 'meta' => []]);
    $slug ??= 'bai-'.$entry->id;
    $entry->translations()->create(['locale' => $locale, 'title' => $title, 'slug' => $slug, 'status' => 'published', 'excerpt' => $excerpt]);
    SiteRoute::query()->create(['locale' => $locale, 'path' => '/'.$slug, 'entry_id' => $entry->id]);
    if ($categories !== []) {
        $entry->assignCategories($categories);
    }

    return $entry;
}

function spCategory(string $name, string $vocabulary = 'post_cat', int $position = 0): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => $vocabulary, 'parent_id' => null, 'position' => $position]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => Str::slug($name)]);
    app(TaxonomyPaths::class)->refreshBranch($category->id);

    return $category->refresh();
}

it('shows the results page kept out of search engines, never cached, with its breadcrumb, groups and counts', function (): void {
    spSite(1);
    app(Tenancy::class)->enter(1, function (): void {
        $news = spCategory('Tin tức');
        spPost('Đồ gá hàn khung', 'Bài về đồ gá', [$news->id], 'do-ga-han-khung');
        spPost('Tin đồ gá mới', null, [$news->id]);
        $shelf = spCategory('Jig', 'product_cat');
        $product = Product::query()->create(['status' => 'published', 'sku' => 'AV-DG-01', 'published_at' => now()->subMinute()]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá kẹp', 'slug' => 'do-ga-kep']);
        $product->taxonomies()->attach($shelf->id, ['site_id' => 1, 'position' => 0]);
    });

    $response = $this->get('http://a.test/tim-kiem?q='.rawurlencode('đồ gá'))->assertOk();
    $html = $response->getContent();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($html)
        ->toContain('<meta name="robots" content="noindex, follow">')
        ->not->toContain('rel="canonical"')
        ->toMatch('#<a class="sf-crumbs__link" href="/">Trang chủ</a>#')
        ->toContain('<span class="sf-crumbs__current" aria-current="page">Tìm kiếm</span>')
        ->toContain('<h1 class="sf-search__h1">Kết quả tìm kiếm</h1>')
        ->toContain('<p class="sf-search__sum" role="status"><b>3 kết quả</b> cho “đồ gá”</p>')
        ->toContain('<nav aria-label="Nhóm kết quả">')
        ->toContain('<a class="sf-search__gchip" href="#nhom-sp">Sản phẩm<span class="sf-search__cnt">1</span></a>')
        ->toContain('<a class="sf-search__gchip" href="#nhom-tin-tuc">Tin tức<span class="sf-search__cnt">2</span></a>')
        ->toContain('<h2 class="sf-search__grp-h" id="nhom-sp-h">Sản phẩm<span class="sf-search__cnt">1</span></h2>')
        // the post's link is its own address (PathBuilder), the bold made of the matched words
        ->toContain('<a class="sf-search__rw" href="/do-ga-han-khung">')
        ->toContain('<span class="sf-search__t"><b>Đồ gá</b> hàn khung</span>')
        ->toContain('<span class="sf-search__d">Bài về <b>đồ gá</b></span>')
        ->toContain('<span class="sf-search__m">Jig · Mã: AV-DG-01</span>')
        ->toContain('<span class="sf-search__img sf-search__img--ph" aria-hidden="true">')
        ->and(BlockStyles::needed($html))->toContain('resources/css/sf-search.css');
    // the products come first, then the categories
    expect(strpos($html, 'id="nhom-sp"'))->toBeLessThan(strpos($html, 'id="nhom-tin-tuc"'));
});

it('marks the matched words in bold out of escaped text only, never the author’s markup', function (): void {
    spSite(1);
    app(Tenancy::class)->enter(1, fn () => spPost('<script>alert(1)</script> Khuôn mẫu', '<img src=x onerror=alert(2)> khuôn', [], 'khuon-x'));

    $html = $this->get('http://a.test/tim-kiem?q=khuon')->assertOk()->getContent();

    expect($html)
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; <b>Khuôn</b> mẫu')
        ->toContain('<b>khuôn</b>');
});

it('continues a group with “Xem thêm”, a plain link to its next rows by a cursor, no page numbers', function (): void {
    spSite(1);
    app(Tenancy::class)->enter(1, function (): void {
        $news = spCategory('Tin tức');
        foreach (range(1, 5) as $i) {
            spPost('Khuôn số '.$i, null, [$news->id], 'khuon-so-'.$i, at: now()->subMinutes(10 - $i));
        }
    });

    $html = $this->get('http://a.test/tim-kiem?q=khuon')->assertOk()->getContent();
    preg_match('#<a class="sf-search__more" href="([^"]+)" data-search-more>Xem thêm 2 tin tức<svg#', $html, $more);

    expect($more)->toHaveCount(2)
        ->and(substr_count($html, 'class="sf-search__rw"'))->toBe(3)
        ->and($html)->not->toContain('page=')->not->toContain('sf-search__gchip');   // one group: no jump links

    $next = $this->get('http://a.test'.html_entity_decode($more[1]))->assertOk()->getContent();
    expect(substr_count($next, 'class="sf-search__rw"'))->toBe(2)
        ->and($next)->toContain('href="/khuon-so-2"')->toContain('href="/khuon-so-1"')
        ->not->toContain('href="/khuon-so-5"')->not->toContain('data-search-more')->not->toContain('<nav aria-label="Nhóm kết quả">');
});

it('says nothing was found, with tips, the main groups and the site’s main action only when it has one', function (): void {
    spSite(1, ctaItem: true);
    spSite(2);
    app(Tenancy::class)->enter(1, function (): void {
        $product = Product::query()->create(['status' => 'published', 'sku' => 'AV-DG-01', 'published_at' => now()->subMinute()]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Bàn kẹp', 'slug' => 'ban-kep']);
    });

    $html = $this->get('http://a.test/tim-kiem?q='.rawurlencode('bánh răng côn xoắn'))->assertOk()->getContent();
    expect($html)
        ->toContain('<h1 class="sf-search__none" role="status">Không tìm thấy “bánh răng côn xoắn”</h1>')
        ->not->toContain('sf-search__h1')
        ->toContain('<p class="sf-search__p">Bạn có thể thử:</p>')
        ->toContain('<li>Bớt từ, ví dụ chỉ gõ “bánh răng”.</li>')
        ->toContain('<li>Kiểm tra chính tả. Gõ có dấu hay không dấu đều được.</li>')
        ->toContain('<li>Tìm theo mã sản phẩm, ví dụ AV-DG-01.</li>')
        ->toContain('Hoặc xem các nhóm chính:')
        ->toContain('<li><a class="sf-schip" href="/san-pham">Sản phẩm</a></li>')
        ->toContain('<b>Chưa thấy đúng thứ bạn cần?</b>')
        ->toContain('<a class="sf-search__btn" href="/bao-gia">Gửi yêu cầu báo giá</a>');

    $html = $this->get('http://b.test/tim-kiem?q=khong-co')->assertOk()->getContent();
    expect($html)->toContain('Không tìm thấy “khong-co”')
        ->not->toContain('sf-search__cta')
        ->not->toContain('Tìm theo mã sản phẩm')
        ->not->toContain('Bớt từ');
});

it('takes the example product code only from a product a visitor of the page may see, the newest dated first', function (): void {
    spSite(1);
    app(Tenancy::class)->enter(1, function (): void {
        $make = function (string $sku, ?CarbonInterface $at, array $locales = ['vi']): Product {
            $product = Product::query()->create(['status' => 'published', 'sku' => $sku, 'published_at' => $at]);
            foreach ($locales as $locale) {
                $product->translations()->create(['locale' => $locale, 'name' => 'Hàng '.$sku, 'slug' => strtolower($sku)]);
            }

            return $product;
        };
        $make('AV-CU-01', now()->subDays(2));
        $make('KHONG-NGAY', null);                       // no date: after every dated one
        $make('HEN-GIO', now()->addDay());               // scheduled: not on the website yet
        $make('CHI-EN', now()->subHour(), ['en']);       // no translation in the page's language
        $make('THUNG-RAC', now()->subMinute())->delete();
    });

    $html = $this->get('http://a.test/tim-kiem?q=khong-co-gi')->assertOk()->getContent();
    expect($html)->toContain('<li>Tìm theo mã sản phẩm, ví dụ AV-CU-01.</li>')
        ->not->toContain('HEN-GIO')->not->toContain('KHONG-NGAY')->not->toContain('CHI-EN')->not->toContain('THUNG-RAC');
});

it('shows the page with its box and the main groups when nothing or one letter is typed', function (string $typed): void {
    spSite(1);

    $html = $this->get('http://a.test/tim-kiem'.$typed)->assertOk()->getContent();

    expect($html)->toContain('<h1 class="sf-search__h1">Kết quả tìm kiếm</h1>')
        ->toContain('id="sf-search-page-q" type="search" name="q"')
        ->toContain('Các nhóm chính:')
        ->toContain('<li><a class="sf-schip" href="/tin-tuc">Tin tức</a></li>')
        ->not->toContain('sf-search__sum')->not->toContain('Không tìm thấy');
})->with(['', '?q=', '?q=a', '?q=%20%20']);

it('asks a visitor who searches too fast to wait, in plain words, with a 429', function (): void {
    spSite(1);
    foreach (range(1, SearchController::MAX_PER_MINUTE) as $i) {
        RateLimiter::hit('site-search:1|127.0.0.1', 60);
    }

    $response = $this->get('http://a.test/tim-kiem?q=khuon')->assertStatus(429);

    expect($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toContain('Bạn tìm hơi nhanh')->toContain('Vui lòng đợi một phút rồi tìm lại.');
    // the other site's visitors are counted apart; an empty search is never counted
    spSite(2);
    $this->get('http://b.test/tim-kiem?q=khuon')->assertOk();
    $this->get('http://a.test/tim-kiem')->assertOk();
});

it('turns the search off everywhere with the header setting: no button, no 404 box, and the address is the 404', function (): void {
    spSite(1, ['search' => false]);

    $html = $this->get('http://a.test/tim-kiem?q=khuon')->assertNotFound()->getContent();
    expect($html)->toContain('class="sf-404"')->not->toContain('sf-sbtn')->not->toContain('sf-404__search')->not->toContain('sf-spanel');

    $missing = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound()->getContent();
    expect($missing)->not->toContain('sf-404__search')->not->toContain('data-search-open');

    // on (the setting missing, or anything but false): the 404 page has the box
    spSite(2, ['search' => 'yes']);
    $missing = $this->get('http://b.test/khong-co-trang-nay')->assertNotFound()->getContent();
    expect($missing)->toContain('<div class="sf-404__search">')
        ->toContain('<label class="sf-sform__label" for="sf-404-q">Tìm trên website</label>')
        ->toContain('<form class="sf-sform" role="search" action="/tim-kiem" method="get">');
});

it('serves the page in every public language under its prefix, and the default language unprefixed', function (): void {
    spSite(1);
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(Tenancy::class)->enter(1, function (): void {
        spPost('Khuôn mẫu', null, [], 'khuon-vi');
        spPost('Mould making', null, [], 'mould', 'en');
    });

    $en = $this->get('http://a.test/en/tim-kiem?q=mould')->assertOk()->getContent();
    expect($en)->toContain('<html lang="en"')->toContain('href="/en/mould"')->toContain('action="/en/tim-kiem"')->not->toContain('khuon-vi');

    $this->get('http://a.test/vi/tim-kiem?q=khuon')->assertRedirect('/tim-kiem?q=khuon')->assertStatus(301);
    $this->get('http://a.test/ko/tim-kiem?q=khuon')->assertNotFound();
    // the header and the 404 page link to the search page of the page's language
    expect($this->get('http://a.test/en/khong-co')->getContent())->toContain('href="/en/tim-kiem"');
});

it('serves an ordinary nested address whose first part is no language of the site, and the search under a public one', function (): void {
    spSite(1);
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(Tenancy::class)->enter(1, function (): void {
        // a post under a two-letter category ("tv"), its address /tv/tim-kiem
        $post = spPost('Tìm kiếm tài năng trên TV', 'Bài trong mục TV', slug: 'tim-kiem-tv');
        SiteRoute::query()->where('entry_id', $post->id)->update(['path' => '/tv/tim-kiem']);
    });

    expect($this->get('http://a.test/tv/tim-kiem')->assertOk()->getContent())->toContain('Tìm kiếm tài năng trên TV')->not->toContain('class="sf-search')
        ->and($this->get('http://a.test/en/tim-kiem')->assertOk()->getContent())->toContain('class="sf-search');
    $this->get('http://a.test/hn/tim-kiem')->assertNotFound();
});

it('reserves the search address: no content may take /tim-kiem', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        expect(fn () => app(RouteSync::class)->sync($entry, 'vi', 'tim-kiem'))->toThrow(RouteConflictException::class)
            ->and(SiteRoute::query()->where('path', '/tim-kiem')->exists())->toBeFalse();
    });
});

it('leaves the address to content the site already had there, and takes it back once that content is not shown', function (): void {
    spSite(1);
    // A post that took /tim-kiem before the address was reserved (written straight to the table, as then).
    $old = app(Tenancy::class)->enter(1, fn () => spPost('Bài cũ ở địa chỉ tìm kiếm', 'Nội dung có sẵn', slug: 'tim-kiem'));

    $html = $this->get('http://a.test/tim-kiem?q=khuon')->assertOk()->getContent();
    expect($html)->toContain('Bài cũ ở địa chỉ tìm kiếm')->not->toContain('class="sf-search');

    // Back to a draft, the post is not shown, so the address is the search page again.
    app(Tenancy::class)->enter(1, function () use ($old): void {
        $old->forceFill(['status' => 'draft'])->save();
        $old->translations()->update(['status' => 'draft']);
    });
    expect($this->get('http://a.test/tim-kiem?q=khuon')->assertOk()->getContent())->toContain('class="sf-search');
});

it('lets content at the address in one language keep it there only, never in the other languages', function (): void {
    spSite(1);
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(Tenancy::class)->enter(1, fn () => spPost('Old search page', slug: 'tim-kiem', locale: 'en'));

    expect($this->get('http://a.test/en/tim-kiem')->assertOk()->getContent())->toContain('Old search page')
        ->and($this->get('http://a.test/tim-kiem')->assertOk()->getContent())->toContain('class="sf-search');
});

it('draws the header’s magnifier after the menu behind a line, and the panel as a plain search form', function (): void {
    spSite(1);

    $html = $this->get('http://a.test/')->assertOk()->getContent();

    expect($html)
        ->toMatch('#</ul>\s*<span class="sf-sdiv" aria-hidden="true"></span>\s*<a class="sf-sbtn sf-sbtn--desk" href="/tim-kiem" aria-label="Tìm kiếm" aria-controls="sf-search-panel" data-search-open>#')
        ->toContain('<span class="sf-tip" aria-hidden="true">Tìm kiếm</span>')
        ->toContain('<div class="sf-spanel" id="sf-search-panel" role="dialog" aria-label="Tìm kiếm" hidden data-search-panel>')
        ->toContain('<form class="sf-sform sf-sform--panel" role="search" action="/tim-kiem" method="get">')
        ->toContain('placeholder="Tìm sản phẩm, dịch vụ, dự án, tin tức…" aria-label="Tìm trên website"')
        ->toContain('maxlength="100"')
        ->toContain('<button type="submit" class="sf-sfield__go">Tìm</button>')
        ->toContain('aria-label="Đóng tìm kiếm" data-search-close>')
        ->toContain('<p class="sf-spanel__label" id="sf-search-quick">Gợi ý nhanh</p>')
        ->toContain('<li><a class="sf-schip" href="/san-pham">Sản phẩm</a></li>');

    spSite(2, ['search' => false]);
    expect($this->get('http://b.test/')->getContent())->not->toContain('sf-sbtn')->not->toContain('sf-spanel')->not->toContain('sf-sdiv');
});

it('counts the search button in the menu-width rule (room 698 → 629px at 1024)', function (): void {
    $menu = [];
    $flipped = null;
    foreach (range(1, 20) as $n) {
        $menu[] = ['label' => 'Mục', 'url' => '/muc-'.$n, 'children' => []];
        if (StorefrontChrome::menuNeedsXl($menu, true) !== StorefrontChrome::menuNeedsXl($menu)) {
            $flipped = $n;
            break;
        }
    }

    // some menu fits beside the logo without the button but not with it; none the other way round
    expect($flipped)->not->toBeNull()
        ->and(StorefrontChrome::menuNeedsXl($menu, true))->toBeTrue()
        ->and(StorefrontChrome::menuNeedsXl($menu))->toBeFalse();
});

it('puts the magnifier before the call in the phone corner when there is no bottom bar, and alone where the bar carries the call', function (): void {
    spSite(1);
    // a menu, so the header has the menu button and its corner; no bottom bar: magnifier then call, on every width
    $html = $this->get('http://a.test/')->getContent();
    expect($html)->toContain('<div class="sf-container sf-bar__inner sf-bar__inner--drawer sf-bar__inner--pair">')
        ->toMatch('#<span class="sf-bar__corner"><a class="sf-sbtn sf-sbtn--corner" href="/tim-kiem"[^>]*>.*?</a>\s*<a class="sf-bar__call" href="tel:0901234567" aria-label="Gọi 0901 234 567">#s')
        ->not->toContain('sf-sbtn--corner-wide');

    // the bottom bar carries the call: the magnifier alone on phones (the header call stays for tablets only)
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => 'floating_contact'],
        ['value' => ['enabled' => true, 'theme' => 'horizontal', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24,
            'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']]]],
    ));
    app(StorefrontSettings::class)->forget();
    expect($this->get('http://a.test/')->getContent())
        ->toMatch('#<span class="sf-bar__corner"><a class="sf-sbtn sf-sbtn--corner" href="/tim-kiem"[^>]*>.*?</a>\s*<a class="sf-bar__call sf-bar__call--hasbar"#s')
        ->not->toContain('sf-bar__inner--pair');

    // search off: the call alone
    spSite(2, ['search' => false]);
    $off = $this->get('http://b.test/')->getContent();
    expect($off)->toContain('<span class="sf-bar__corner"><a class="sf-bar__call" href="tel:0901234567"')
        ->not->toContain('sf-sbtn')->not->toContain('sf-bar__inner--pair');

    // no phone number (and no bottom bar): the magnifier alone
    app(Tenancy::class)->enter(2, fn () => SiteSetting::query()->where('key', 'header')->update(['value' => ['top_bar' => ['items' => []]]]));
    app(Tenancy::class)->enter(2, fn () => SiteSetting::query()->where('key', 'brand')->update(['value' => ['name' => 'Xưởng Mẫu']]));
    app(StorefrontSettings::class)->forget();
    $noPhone = $this->get('http://b.test/')->getContent();
    preg_match('#<span class="sf-bar__corner">(.*?)</span>\s*</div>#s', $noPhone, $cell);
    expect($cell[1] ?? null)->not->toBeNull()
        ->and($cell[1])->toContain('<a class="sf-sbtn sf-sbtn--corner" href="/tim-kiem"')->not->toContain('sf-bar__call')
        ->and($noPhone)->not->toContain('sf-bar__inner--pair');

    // the corner set to search (or to the cart the site does not have): the magnifier alone; none: nothing
    foreach (['search' => true, 'cart' => true, 'none' => false] as $corner => $magnifier) {
        app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('key', 'header')->update(['value' => ['top_bar' => ['items' => []], 'mobile_corner' => $corner]]));
        app(StorefrontSettings::class)->forget();
        $page = $this->get('http://a.test/')->getContent();
        preg_match('#<span class="sf-bar__corner">(.*?)</span>\s*</div>#s', $page, $cell);
        expect($cell[1] ?? null)->not->toBeNull()
            ->and(str_contains($cell[1], 'sf-sbtn--corner"'))->toBe($magnifier, $corner)
            ->and($cell[1])->not->toContain('sf-bar__call');
    }
});

it('gives a phone header corner button a 44px hit area on touch even where the root is 15px, growing with a larger text size', function (): void {
    // measured in headless Chrome at 320 and 390 with a 15px root (a phone's smallest): 41.25px before, 44px after
    $css = (string) file_get_contents(resource_path('css/storefront.css'));
    $rule = "@media (any-pointer: coarse) {\n    .sf-bar__corner > :is(.sf-sbtn, .sf-bar__call) {\n        width: max(44px, 2.75rem);\n        height: max(44px, 2.75rem);\n    }\n}";

    expect($css)->toContain($rule)
        // the phone pair's own fixed 44px comes later, so it still wins there
        ->and(strpos($css, $rule))->toBeLessThan(strpos($css, '.sf-bar__inner--pair :is(.sf-sbtn, .sf-bar__call) {'));
});
