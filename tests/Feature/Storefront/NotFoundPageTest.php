<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/*
 * A page the storefront cannot find answers with the site's own 404 page: the site's header,
 * footer and menus around the message, rendered inside the site's context (never another site's
 * brand or menu), with a real 404 status and kept out of search engines. Scanner paths (a dot in
 * the path) get the plain 404 before any of the site's data is read; an unknown host gets the
 * neutral page with no site data at all.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1, 'Ánh Kim Hà');
    seedHomepage(2, 'Ước Mơ Sứ');
    notFoundSite(1, 'Ánh Kim Hà', ['Ấn phẩm hè' => '/an-pham-he', 'Đồ gá Ý' => '/do-ga-y']);
    notFoundSite(2, 'Ước Mơ Sứ', ['Ứng dụng sứ' => '/ung-dung-su', 'Ốc vít Đà' => '/oc-vit-da']);
});

/**
 * Give a site its brand name and a main menu: a link home first, then the given links.
 *
 * @param  array<string, string>  $links  label → site path
 */
function notFoundSite(int $siteId, string $brand, array $links): void
{
    app(Tenancy::class)->enter($siteId, function () use ($brand, $links): void {
        SiteProfile::query()->create(['brand_name' => $brand]);
        $home = Entry::query()->firstOrFail();
        $menu = Menu::query()->create(['name' => 'Chính', 'handle' => 'primary']);
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'entry', 'routable_type' => 'entry', 'routable_id' => $home->id, 'position' => 0]);
        $item->translations()->create(['locale' => 'vi', 'label' => 'Trang chủ']);
        $position = 1;
        foreach ($links as $label => $url) {
            $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => $url, 'position' => $position++]);
            $item->translations()->create(['locale' => 'vi', 'label' => $label]);
        }
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id]);
    });
}

/** Name a site's contact page (a published page at /lien-he-voi-chung-toi) in its settings. */
function notFoundContactPage(int $siteId): void
{
    app(Tenancy::class)->enter($siteId, function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he-voi-chung-toi', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/lien-he-voi-chung-toi', 'entry_id' => $entry->id]);
        SiteSetting::query()->create(['group_name' => 'catalog', 'key' => 'contact_page', 'value' => ['entry' => $entry->ulid]]);
    });
}

it('shows each site its own header, footer and menus on a 404, never the other site’s', function (): void {
    foreach ([['a.test', 1], ['b.test', 2], ['a.test', 1], ['b.test', 2]] as [$host, $site]) {
        $html = $this->get("http://{$host}/khong-co-trang-nay")->assertNotFound()->getContent();

        [$own, $other] = $site === 1
            ? [['Ánh Kim Hà', 'Ấn phẩm hè', 'Đồ gá Ý'], ['Ước Mơ Sứ', 'Ứng dụng sứ', 'Ốc vít Đà']]
            : [['Ước Mơ Sứ', 'Ứng dụng sứ', 'Ốc vít Đà'], ['Ánh Kim Hà', 'Ấn phẩm hè', 'Đồ gá Ý']];

        expect($html)
            ->toContain('class="sf-404"')
            ->toContain('class="site-header"')
            ->toContain('class="site-footer"')
            ->toContain('<p class="sf-404__code">404</p>')
            ->toContain('<h1 class="sf-404__title">Không tìm thấy trang này</h1>')
            ->toContain('<p class="sf-404__text">Trang bạn tìm có thể đã đổi địa chỉ hoặc không còn.</p>');
        expect(substr_count($html, '<h1'))->toBe(1);
        foreach ($own as $text) {
            expect($html)->toContain($text);
        }
        foreach ($other as $text) {
            expect($html)->not->toContain($text);
        }
    }
});

it('suggests the main menu’s links to the site’s pages, without the home page', function (): void {
    $html = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound()->getContent();

    preg_match('~<ul class="sf-404__links">(.*?)</ul>~s', $html, $list);

    expect($list[1] ?? '')
        ->toContain('<a class="sf-404__link" href="/an-pham-he">Ấn phẩm hè</a>')
        ->toContain('<a class="sf-404__link" href="/do-ga-y">Đồ gá Ý</a>')
        ->not->toContain('Trang chủ');
});

it('leaves out the suggestions when the main menu has no links', function (): void {
    app(Tenancy::class)->enter(2, fn () => MenuAssignment::query()->delete());

    $html = $this->get('http://b.test/khong-co-trang-nay')->assertNotFound()->getContent();

    expect($html)->toContain('Không tìm thấy trang này')
        ->not->toContain('sf-404__more');
});

it('suggests at most five links, and draws no button, contact line or search box of its own', function (): void {
    notFoundContactPage(1);
    app(Tenancy::class)->enter(1, function (): void {
        $menu = Menu::query()->firstOrFail();
        foreach (['Một', 'Hai', 'Ba', 'Bốn', 'Năm'] as $i => $label) {
            $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '/muc-'.($i + 1), 'position' => 10 + $i]);
            $item->translations()->create(['locale' => 'vi', 'label' => $label]);
        }
    });

    $html = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound()->getContent();

    preg_match('~<ul class="sf-404__links">(.*?)</ul>~s', $html, $list);

    expect(substr_count($list[1] ?? '', '<li>'))->toBe(5)
        ->and($list[1] ?? '')->toContain('Ấn phẩm hè')->toContain('Ba')->not->toContain('Bốn')
        ->and($html)->not->toContain('sf-404__btn')
        ->not->toContain('Liên hệ Ánh Kim Hà')
        ->not->toContain('role="search"');
});

it('keeps the 404 page out of search engines, with no address, share tags or alternates of its own', function (): void {
    $html = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound()->getContent();

    expect($html)
        ->toContain('<meta name="robots" content="noindex">')
        ->toContain('<title>Không tìm thấy trang')
        ->not->toContain('rel="canonical"')
        ->not->toContain('og:title')
        ->not->toContain('hreflang=')
        ->not->toContain('application/ld+json');
});

it('answers a list page number that does not exist with the same 404 page, never a 200', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        app(RouteSync::class)->syncTaxonomy($category, 'vi', 'tin-tuc');
    });

    $this->get('http://a.test/tin-tuc')->assertOk();
    $response = $this->get('http://a.test/tin-tuc?page=999');

    $response->assertStatus(404);
    expect($response->getContent())->toContain('class="sf-404"')->toContain('Ánh Kim Hà')->not->toContain('Ước Mơ Sứ');
});

it('answers a product that is not published with the same 404 page', function (): void {
    $path = app(Tenancy::class)->enter(1, function (): string {
        $product = Product::query()->create(['status' => 'draft']);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá nháp Ý', 'slug' => 'do-ga-nhap']);

        return app(RouteSync::class)->syncProduct($product->id, null, 'vi', 'do-ga-nhap');
    });

    $html = $this->get('http://a.test'.$path)->assertNotFound()->getContent();

    expect($html)->toContain('class="sf-404"')->toContain('Ánh Kim Hà')->not->toContain('Đồ gá nháp Ý');
});

it('gives scanner paths the plain 404 before reading any of the site’s pages, settings or menus', function (string $path): void {
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $html = $this->get('http://a.test'.$path)->assertNotFound()->getContent();
        $queries = array_column(DB::getQueryLog(), 'query');
        $sql = implode("\n", $queries);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($html)->toContain('class="error-page"')->not->toContain('sf-404')
        ->and($sql)->not->toContain('"routes"')
        ->and(count(array_filter($queries, fn (string $query): bool => str_contains($query, '"redirects"'))))->toBe(1)
        ->and($sql)->not->toContain('"site_settings"')
        ->and($sql)->not->toContain('"menu_assignments"');
})->with(['/wp-login.php', '/.env', '/.git/config', '/wp-content/config.php.bak', '/xmlrpc.php', '/wp-login%2ephp', '/%2eenv', '/WP-LOGIN%2EPHP']);

it('follows a stored redirect for a path with a dot, with its status and target', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->firstOrFail();
        Redirect::query()->create(['locale' => 'vi', 'from_path' => '/brochure.pdf', 'to_path' => '/tai-lieu', 'entry_id' => $entry->id, 'status_code' => 301]);
        Redirect::query()->create(['locale' => 'vi', 'from_path' => '/cu.html', 'to_path' => '/moi', 'entry_id' => $entry->id, 'status_code' => 302]);
    });

    $this->get('http://a.test/brochure.pdf')->assertStatus(301)->assertRedirect('http://a.test/tai-lieu');
    $this->get('http://a.test/cu.html')->assertStatus(302)->assertRedirect('http://a.test/moi');
});

it('never follows a stored redirect that leaves the site, even for a path with a dot', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->firstOrFail();
        Redirect::query()->create(['locale' => 'vi', 'from_path' => '/ra.php', 'to_path' => '//evil.example/x', 'entry_id' => $entry->id, 'status_code' => 301]);
    });

    $this->get('http://a.test/ra.php')->assertNotFound();
});

it('gives an unknown host the neutral page with no site’s data', function (): void {
    $html = $this->get('http://chua-co-website.test/khong-co-trang-nay')->assertNotFound()->getContent();

    expect($html)->toContain('class="sf-404 sf-404--neutral"')
        ->not->toContain('error-page')
        ->not->toContain('chua-co-website')
        ->not->toContain('Ánh Kim Hà')
        ->not->toContain('Ước Mơ Sứ')
        ->not->toContain('Ấn phẩm hè');
});
