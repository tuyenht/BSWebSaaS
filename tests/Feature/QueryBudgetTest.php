<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\View;

/*
 * Query Budget guardrail (CLAUDE.md §6): assert a hard ceiling of DB queries per
 * hot route so N+1 regressions fail CI. Tighten as the render path matures (cache,
 * eager-load); for now it bounds the resolve + render of one page.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1, 'An Vinh');
});

it('renders the storefront home within its query budget', function (): void {
    $budget = 11; // + nav: one assignment lookup (miss → empty menu) on top of the page resolve

    $count = countQueries(fn () => $this->get('http://a.test/')->assertOk());

    expect($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with the site’s search settings within the same budget', function (): void {
    $budget = 11; // the search settings ride the one site-settings query the page already makes
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'seo', 'key' => 'config', 'value' => [
        'titles' => ['vi' => 'Mặc định'], 'descriptions' => ['vi' => 'Mô tả'], 'template' => '%tieu-de% | %ten-website%', 'indexable' => false,
    ]]));

    $count = countQueries(fn () => $this->get('http://a.test/')->assertOk()->assertSee('noindex, nofollow', false));

    expect($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with its brand images set within the budget plus one', function (): void {
    // The four brand images resolve in ONE media query (Storefront BrandImages); with none set the
    // home stays at its own budget (the first test), so this is the home budget + 1.
    $budget = 12;
    $keys = app(Tenancy::class)->enter(1, function (): array {
        $keys = [];
        foreach (['logo', 'logo-dark', 'favicon', 'share'] as $slug) {
            $hash = hash('sha256', $slug);
            $keys[] = Media::query()->create([
                'collection' => 'brand', 'slug' => $slug, 'hash' => $hash, 'storage_key' => "s/brand/{$slug}-".substr($hash, 0, 12).'.png',
                'mime' => 'image/png', 'size' => 1_000, 'width' => 320, 'height' => 80,
            ])->id;
        }
        SiteProfile::query()->create(['brand_name' => 'An Vinh', 'logo_media_id' => $keys[0], 'logo_dark_media_id' => $keys[1], 'favicon_media_id' => $keys[2], 'og_image_media_id' => $keys[3]]);

        return $keys;
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('s/brand/favicon-')->toContain('s/brand/share-')
        ->and($keys)->toHaveCount(4)
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with only the older brand URLs within the home budget', function (): void {
    $budget = 11; // no media id set: the brand images cost no query
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'general', 'key' => 'brand', 'value' => [
        'name' => 'An Vinh', 'logo' => 'https://files.baoson.net/s/brand/logo.png', 'favicon' => 'https://files.baoson.net/s/brand/logo.png?w=64',
    ]]));

    $count = countQueries(fn () => $this->get('http://a.test/')->assertOk()->assertSee('s/brand/logo.png?w=64', false));

    expect($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with the phone bar and its “Mua hàng” button within its query budget', function (): void {
    // The home budget plus finding the product list once for the page: the site's modules (2), the
    // products section, a published product and the list's address. The bar's own setting shares
    // the one settings read the home already makes.
    $budget = 16;

    app(Tenancy::class)->enter(1, function (): void {
        SiteModule::query()->create(['module_key' => 'catalog', 'enabled' => true]);
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm', 'slug' => 'san-pham']);
        ContentSection::query()->create(['key' => 'products', 'label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $category->id]);
        app(RouteSync::class)->syncTaxonomy($category, 'vi', 'san-pham');
        $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm 1', 'slug' => 'sp-1']);
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
            'enabled' => true, 'theme' => 'horizontal', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 24,
            'buy' => ['enabled' => true, 'color' => '#0ab39c'],
            'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678'], ['key' => 'zalo', 'on' => true, 'value' => '0912345678']],
        ]]);
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('tel:0912345678')->toContain('href="/san-pham"')
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with the full chrome within its query budget', function (): void {
    // The home budget plus the three reads any menu costs (items, their labels, link targets).
    // Tokens, header and footer settings share the one settings read the home already makes,
    // and the footer menu rides in the same resolve as the primary one — no query of its own.
    $budget = 14;

    app(Tenancy::class)->enter(1, function (): void {
        foreach ([
            ['theme', 'tokens', ['primary' => '#f59e0b', 'ink' => '#0f172a', 'font' => 'Inter, sans-serif']],
            ['storefront', 'header', ['top_bar' => ['items' => [['kind' => 'phone', 'text' => '0901 234 567', 'value' => '0901234567']]], 'text_size' => true]],
            ['storefront', 'footer', ['about' => 'Giới thiệu.', 'factories' => [['title' => 'Nhà máy 1', 'map_url' => 'https://maps.google.com/?q=1']]]],
        ] as [$group, $key, $value]) {
            SiteSetting::query()->create(['group_name' => $group, 'key' => $key, 'value' => $value]);
        }

        $home = Entry::query()->firstOrFail();
        foreach (['primary' => 'Chính', 'footer' => 'Chân trang'] as $region => $name) {
            $menu = Menu::query()->create(['name' => $name, 'handle' => $region]);
            $parent = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '#', 'position' => 0]);
            $parent->translations()->create(['locale' => 'vi', 'label' => 'Nhóm']);
            foreach (range(1, 3) as $position) {
                $item = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $parent->id, 'link_type' => 'entry',
                    'routable_type' => 'entry', 'routable_id' => $home->id, 'position' => $position]);
                $item->translations()->create(['locale' => 'vi', 'label' => "Mục {$position}"]);
            }
            MenuAssignment::query()->create(['region_key' => $region, 'locale' => 'vi', 'menu_id' => $menu->id]);
        }
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('sf-header')->toContain('sf-footer')->toContain('Mục 3')
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the site’s 404 page with the full chrome within its query budget', function (): void {
    // The page resolve misses (the route, then the redirect check), then the same chrome as the
    // home: one settings read, the brand, the menus' three reads. The contact page costs nothing
    // while the site names none, and the suggested links come from the menu already loaded.
    $budget = 13;

    app(Tenancy::class)->enter(1, function (): void {
        foreach ([
            ['storefront', 'header', ['top_bar' => ['items' => [['kind' => 'phone', 'text' => '0901 234 567', 'value' => '0901234567']]], 'text_size' => true]],
            ['storefront', 'footer', ['about' => 'Giới thiệu.']],
        ] as [$group, $key, $value]) {
            SiteSetting::query()->create(['group_name' => $group, 'key' => $key, 'value' => $value]);
        }

        $home = Entry::query()->firstOrFail();
        foreach (['primary' => 'Chính', 'footer' => 'Chân trang'] as $region => $name) {
            $menu = Menu::query()->create(['name' => $name, 'handle' => $region]);
            foreach (range(1, 3) as $position) {
                $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'entry',
                    'routable_type' => 'entry', 'routable_id' => $home->id, 'position' => $position]);
                $item->translations()->create(['locale' => 'vi', 'label' => "Mục {$position}"]);
            }
            MenuAssignment::query()->create(['region_key' => $region, 'locale' => 'vi', 'menu_id' => $menu->id]);
        }
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound()->getContent();
    });

    expect($html)->toContain('sf-404')->toContain('sf-header')->toContain('sf-footer')->toContain('Mục 3')
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the site’s 404 page within the same query budget when the site names a contact page', function (): void {
    // The contact page is one more read of the site's settings plus its route lookup.
    $budget = 15;

    app(Tenancy::class)->enter(1, function (): void {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/lien-he', 'entry_id' => $page->id]);
        SiteSetting::query()->create(['group_name' => 'catalog', 'key' => 'contact_page', 'value' => ['entry' => $page->ulid]]);
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound()->getContent();
    });

    expect($html)->toContain('sf-404__btn--contact')->toContain('href="/lien-he"')
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders a stats block of plain numbers with no query of its own, and a years item with the one settings read', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $numbers = fn () => View::make('theme::sections.stats', ['props' => ['items' => [['value' => 3, 'label' => 'Nhà máy'], ['value' => 4300, 'label' => 'Nhà xưởng']]]])->render();
        $years = fn () => View::make('theme::sections.stats', ['props' => ['items' => [['kind' => 'years', 'value' => 2014, 'label' => 'Năm'], ['kind' => 'year', 'value' => 2019, 'label' => 'Thành lập']]]])->render();
        $side = fn () => View::make('theme::sections.cta', ['props' => ['side' => 'stats', 'side_items' => [['value' => 3, 'label' => 'Nhà máy']]]])->render();

        expect(countQueries($numbers))->toBe(0)
            ->and(countQueries($side))->toBe(0)
            ->and(countQueries($years))->toBe(1)
            ->and(countQueries($years))->toBe(0);
    });
});
