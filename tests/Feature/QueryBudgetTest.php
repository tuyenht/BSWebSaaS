<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\BlockReference;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
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

it('renders the storefront home placing three shared blocks within the budget plus one', function (): void {
    // One query reads every shared block a page places (body and slots together), however many.
    $budget = 12;
    app(Tenancy::class)->enter(1, function (): void {
        $refs = [];
        foreach (['mot', 'hai', 'ba'] as $key) {
            $block = ContentBlock::query()->create(['key' => $key, 'name' => 'Khối '.$key, 'region' => 'inline', 'status' => 'published']);
            ContentBlockTranslation::query()->create(['content_block_id' => $block->id, 'locale' => 'vi', 'body' => ['blocks' => [
                ['type' => 'cta', 'props' => ['heading' => 'Khối dùng chung '.$key]],
            ]]]);
            $refs[] = ['type' => BlockReference::TYPE, 'props' => [BlockReference::PROP => $block->ulid]];
        }
        EntryTranslation::query()->update(['body' => json_encode(['blocks' => $refs])]);
    });

    $count = countQueries(fn () => $this->get('http://a.test/')->assertOk()->assertSee('Khối dùng chung ba'));

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

it('renders the storefront home with its extra embed code on within the same budget', function (): void {
    $budget = 11; // the embed code rides the one site-settings query the page already makes
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'embed_code', 'value' => [
        'on' => true, 'code' => ['head' => '', 'body_top' => '', 'body_end' => '', 'foot' => '<script>window.qbEmbed = 1;</script>'], 'acknowledged_at' => '2026-11-22T03:00:00Z', 'acknowledged_by' => null,
    ]]));

    $count = countQueries(fn () => $this->get('http://a.test/')->assertOk()->assertSee('<script>window.qbEmbed = 1;</script>', false));

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

it('renders the storefront home with contact buttons drawn by their own pictures within the budget plus one', function (): void {
    // Every channel's own icon picture resolves in ONE media query (Storefront ContactIconImages);
    // with none set the floating contact adds no query, so this is the home budget + 1.
    $budget = 12;
    $keys = app(Tenancy::class)->enter(1, function (): array {
        $pictures = [];
        foreach (['goi', 'zalo'] as $slug) {
            $hash = hash('sha256', 'icon-'.$slug);
            $pictures[$slug] = Media::query()->create([
                'collection' => 'library', 'slug' => $slug, 'hash' => $hash, 'storage_key' => "s/library/{$slug}-".substr($hash, 0, 12).'.png',
                'mime' => 'image/png', 'size' => 2_000, 'width' => 64, 'height' => 64,
            ]);
        }
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'floating_contact', 'value' => [
            'enabled' => true, 'theme' => 'horizontal', 'position' => 'right',
            'channels' => [
                ['key' => 'phone', 'on' => true, 'value' => '0912345678', 'img' => $pictures['goi']->ulid],
                ['key' => 'zalo', 'on' => true, 'value' => '0912345678', 'img' => $pictures['zalo']->ulid],
            ],
        ]]);

        return array_map(static fn (Media $m): string => e(app(MediaUrlResolver::class)->url($m->storage_key)), $pictures);
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('sf-fc__img')->toContain($keys['goi'])->toContain($keys['zalo'])
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with the full chrome within its query budget', function (): void {
    // The home budget plus the three reads any menu costs (items, their labels, link targets).
    // Tokens, header and footer settings share the one settings read the home already makes,
    // and the footer menu rides in the same resolve as the primary one — no query of its own.
    // Plus one: the phone menu asks whether the site has a products section (a site without one stops there).
    $budget = 15;

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

it('renders the storefront home with the phone menu’s product tab within its query budget', function (): void {
    // The no-products home below makes 14 reads (the domain and tenancy ones, the locales, the page, the settings, the brand, the three menu
    // reads, and the phone menu's read of the products section). With a product list it makes 20: six more, listed
    // from the real run. Site type and enabled modules (2: the
    // catalog entitlement), whether a product is published (1), the product list's address (1), and the tree
    // itself (2: the categories below the section, then their names with their routes — two reads however many
    // categories there are).
    $budget = 20;

    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'header', 'value' => ['top_bar' => ['items' => []], 'text_size' => true]]);
        $menu = Menu::query()->create(['name' => 'Chính', 'handle' => 'primary']);
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '/', 'position' => 0]);
        $item->translations()->create(['locale' => 'vi', 'label' => 'Trang chủ']);
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id]);

        SiteModule::query()->create(['module_key' => 'catalog', 'enabled' => true]);
        $root = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $root->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm', 'slug' => 'san-pham']);
        app(TaxonomyPaths::class)->refreshBranch($root->id);
        ContentSection::query()->create(['key' => 'products', 'label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $root->id]);
        app(RouteSync::class)->syncTaxonomy($root, 'vi', 'san-pham');
        $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm 1', 'slug' => 'sp-1']);
        foreach (range(1, 12) as $i) {
            $parent = $i > 8 ? Taxonomy::query()->where('parent_id', $root->id)->orderBy('id')->first()->id : $root->id;
            $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $parent, 'position' => $i]);
            $category->translations()->create(['locale' => 'vi', 'name' => "Nhóm {$i}", 'slug' => "nhom-{$i}"]);
            app(TaxonomyPaths::class)->refreshBranch($category->id);
            app(RouteSync::class)->syncTaxonomy($category, 'vi', "nhom-{$i}");
        }
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('role="tablist"')->toContain('Nhóm 12')->toContain('href="/nhom-1"')
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with a phone menu and no products within its query budget', function (): void {
    // A phone menu and a header but no product list: 14 reads, the products section's among them.
    $budget = 14;

    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'header', 'value' => ['top_bar' => ['items' => []], 'text_size' => true]]);
        $menu = Menu::query()->create(['name' => 'Chính', 'handle' => 'primary']);
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '/', 'position' => 0]);
        $item->translations()->create(['locale' => 'vi', 'label' => 'Trang chủ']);
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id]);
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('id="mobile-menu"')->not->toContain('role="tablist"')
        ->and($count)->toBeLessThanOrEqual($budget);
});

/** The main menu of site 1 with one top-level item whose large panel shows a published shared block. */
function qbBlockPanelMenu(): void
{
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'header', 'value' => ['sticky' => false]]);
        $block = ContentBlock::query()->create(['key' => 'bang-lon', 'name' => 'Bảng lớn', 'region' => 'menu', 'status' => 'published']);
        ContentBlockTranslation::query()->create(['content_block_id' => $block->id, 'locale' => 'vi', 'body' => ['blocks' => [
            ['type' => 'cta', 'props' => ['heading' => 'Bảng lớn dùng chung']],
        ]]]);
        $home = Entry::query()->firstOrFail();
        $menu = Menu::query()->create(['name' => 'Chính', 'handle' => 'primary']);
        foreach (range(1, 3) as $position) {
            $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'entry', 'routable_type' => 'entry', 'routable_id' => $home->id,
                'position' => $position, 'meta' => $position === 1 ? ['mega' => ['type' => 'block', 'block' => $block->ulid]] : []]);
            $item->translations()->create(['locale' => 'vi', 'label' => "Mục {$position}"]);
        }
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id]);
    });
}

it('renders the storefront home with a large panel showing a shared block within the menu budget plus one', function (): void {
    // The home budget, the menu's three reads and the phone menu's products-section read, plus ONE read
    // for every shared block the large panels show (with the page's own references when it has any).
    $budget = 16;
    qbBlockPanelMenu();

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('sf-nav__panel--block')->toContain('Bảng lớn dùng chung')
        ->and($count)->toBeLessThanOrEqual($budget);
});

it('renders the site’s 404 page with the full chrome within its query budget', function (): void {
    // The page resolve misses (the route, then the redirect check), then the same chrome as the
    // home: one settings read, the brand, the menus' three reads. The contact page costs nothing
    // while the site names none, and the suggested links come from the menu already loaded. Plus the
    // phone menu's one read for a products section.
    $budget = 14;

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

it('renders the site’s 404 page with a large panel showing a shared block within its budget plus one', function (): void {
    // The 404 page's own budget (no footer menu here, so one read less than with the full chrome)
    // plus the one read of the panels' blocks.
    $budget = 15;
    qbBlockPanelMenu();

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/khong-co-trang-nay')->assertNotFound()->getContent();
    });

    expect($html)->toContain('sf-404')->toContain('sf-nav__panel--block')->toContain('Bảng lớn dùng chung')
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

it('renders the site search results page on one fixed query budget, however many rows and groups it shows', function (): void {
    // The chrome as on the 404 page (settings, brand, menus, the phone menu's products read), the rate
    // limit in the cache (no query), then the search: one plan statement (word counts and matched
    // categories), one for the posts and pages, one for the products; then the rows shown: the
    // category names, the posts with their translations and addresses, the products with their
    // translations, categories (and their names) and pictures. The same count for 1 row per group or 3.
    // One more: whether content the site had before the address was reserved still holds it.
    $budget = 24;
    $seed = function (int $site, int $perGroup): void {
        app(Tenancy::class)->enter($site, function () use ($site, $perGroup): void {
            foreach (['Tin tức', 'Dự án'] as $i => $name) {
                $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => $i]);
                $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => 'nhom-'.$category->id]);
                app(TaxonomyPaths::class)->refreshBranch($category->id);
                foreach (range(1, $perGroup) as $n) {
                    $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()->subMinutes($n), 'meta' => []]);
                    $entry->translations()->create(['locale' => 'vi', 'title' => "Khuôn {$name} {$n}", 'slug' => 'khuon-'.$entry->id, 'status' => 'published', 'excerpt' => 'Khuôn mẫu']);
                    SiteRoute::query()->create(['locale' => 'vi', 'path' => '/khuon-'.$entry->id, 'entry_id' => $entry->id]);
                    $entry->assignCategories([$category->id]);
                }
            }
            $shelf = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
            $shelf->translations()->create(['locale' => 'vi', 'name' => 'Kệ', 'slug' => 'ke-'.$shelf->id]);
            app(TaxonomyPaths::class)->refreshBranch($shelf->id);
            foreach (range(1, $perGroup) as $n) {
                $product = Product::query()->create(['status' => 'published', 'sku' => "K-{$n}", 'published_at' => now()->subMinutes($n)]);
                $product->translations()->create(['locale' => 'vi', 'name' => "Khuôn {$n}", 'slug' => 'khuon-sp-'.$product->id]);
                $product->taxonomies()->attach($shelf->id, ['site_id' => $site, 'position' => 0]);
            }
            SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => ['top_bar' => ['items' => []]]]);
        });
    };
    $measure = function (string $host): array {
        $html = '';
        $count = countQueries(function () use (&$html, $host): void {
            $html = $this->get("http://{$host}/tim-kiem?q=khuon")->assertOk()->getContent();
        });

        return [$count, substr_count($html, 'class="sf-search__rw"')];
    };

    $seed(1, 1);
    $seed(2, 3);   // three of each: still one read per kind
    [$few, $fewRows] = $measure('a.test');
    [$many, $manyRows] = $measure('b.test');

    expect($fewRows)->toBe(3)->and($manyRows)->toBe(9)
        ->and($many)->toBe($few)
        ->and($many)->toBeLessThanOrEqual($budget);
});

it('answers the search suggestions on one small fixed query budget, whatever rows it shows', function (): void {
    // The site (its domain, the tenant setting and its check, whether it is open), its languages,
    // whether search is on (settings), the rate limit in the cache (no query), then one plan statement
    // (word counts and matched categories), the forms' thank-you pages, one statement for the posts and
    // pages, one for the products (each with its first photo's media key, in the same statement), one
    // for the category names and addresses, the site's day format for a dated row; then «Xem tất cả N»:
    // the search page's plan and one count stopped at 51. No chrome, no models.
    $budget = 14;
    $seed = function (int $site, int $each): void {
        app(Tenancy::class)->enter($site, function () use ($each): void {
            $news = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'list_template' => 'grid-news']);
            $news->translations()->create(['locale' => 'vi', 'name' => 'Khuôn tin', 'slug' => 'khuon-tin']);
            app(TaxonomyPaths::class)->refreshBranch($news->id);
            SiteRoute::query()->create(['locale' => 'vi', 'path' => '/khuon-tin', 'taxonomy_id' => $news->id]);
            foreach (range(1, $each) as $n) {
                $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()->subMinutes($n), 'meta' => []]);
                $entry->translations()->create(['locale' => 'vi', 'title' => "Khuôn bài {$n}", 'slug' => 'khuon-'.$entry->id, 'status' => 'published']);
                SiteRoute::query()->create(['locale' => 'vi', 'path' => '/khuon-'.$entry->id, 'entry_id' => $entry->id]);
                $entry->assignCategories([$news->id]);
                // one code that starts with the words: the other rows are titles, a dated news post among them
                $product = Product::query()->create(['status' => 'published', 'sku' => $n === 1 ? 'KHUON-1' : "SP-{$n}", 'published_at' => now()->subMinutes($n)]);
                $product->translations()->create(['locale' => 'vi', 'name' => "Khuôn sản phẩm {$n}", 'slug' => 'khuon-sp-'.$product->id]);
                SiteRoute::query()->create(['locale' => 'vi', 'path' => '/khuon-sp-'.$product->id, 'product_id' => $product->id]);
                $media = Media::query()->create(['storage_key' => "tenant/products/khuon-{$product->id}-0123456789ab.jpg", 'mime' => 'image/jpeg', 'size' => 10, 'original_filename' => 'k.jpg', 'hash' => str_pad((string) $product->id, 64, 'a'), 'slug' => 'khuon-'.$product->id]);
                $product->images()->create(['media_id' => $media->id, 'position' => 0]);
            }
        });
    };
    $measure = function (string $host): array {
        $json = [];
        $count = countQueries(function () use (&$json, $host): void {
            $json = $this->get("http://{$host}/tim-kiem/goi-y?q=khuon")->assertOk()->json();
        });

        return [$count, array_count_values(array_column($json['items'], 'kind'))];
    };

    $seed(1, 1);
    $seed(2, 4);   // more than fit, each with a photo: still one read per kind
    [$few, $fewKinds] = $measure('a.test');
    [$many, $manyKinds] = $measure('b.test');

    expect($fewKinds)->toBe(['group' => 1, 'sku' => 1, 'item' => 1])
        ->and($manyKinds)->toBe(['group' => 1, 'sku' => 1, 'item' => 3])
        ->and($many)->toBe($few)
        ->and($many)->toBeLessThanOrEqual($budget);
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

it('renders the admin "Tổng quan" with every card on a flat query budget', function (): void {
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    // A writer who also reviews and runs the website: every card of the screen is on.
    $member = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        grantRole($member, 'everything', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit', 'entries.review', 'entries.approve',
            'settings.manage', 'forms.view', 'domains.manage', 'media.upload', 'menus.manage']);
        SiteModule::query()->create(['module_key' => 'contacts', 'enabled' => true]);

        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        // Enough rows of every kind that a query per row would blow the ceiling.
        $other = User::factory()->create();
        grantRole($other, 'reviewer', ['entries.view', 'entries.edit.own', 'entries.submit', 'entries.review', 'entries.approve']);
        foreach (range(1, 12) as $i) {
            $author = $i % 2 ? $member : $other;
            $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
            $post->translations()->create(['locale' => 'vi', 'title' => "Bài {$i}", 'slug' => "bai-{$i}", 'status' => 'draft', 'body' => ['blocks' => []]]);
            $post->assignCategories([$category->id]);
            if ($i % 3 !== 0) {
                app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $author->fresh(), 'submit');
            }
            if ($i % 4 === 1 && $i % 3 !== 0) {
                app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $other->fresh(), 'reject', "Lý do {$i}");
            }
        }
        $form = Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'status' => 'active']);
        foreach (range(1, 12) as $i) {
            FormSubmission::query()->create(['form_id' => $form->id, 'data' => ['message' => 'x'], 'name' => "Khách {$i}", 'status' => 'new']);
        }
    });

    $this->actingAs($member)->get('http://a.test/admin')->assertOk(); // the permission and settings caches fill on the first request
    $html = '';
    $count = countQueries(function () use ($member, &$html): void {
        $html = $this->actingAs($member)->get('http://a.test/admin')->assertOk()->getContent();
    });

    expect($html)->toContain(__('dash.rev_title'))->toContain(__('dash.wr_title'))->toContain(__('dash.wr_returned_title'))
        ->toContain(__('dash.start_title'))->toContain(__('dash.todo_leads', ['n' => 12]))
        // The shell (tenancy, nav, entitlement) and one bounded read per card: the newest unread
        // leads, the domains, the first steps, the review queue (with its rows' relations), the
        // member's own posts counted up to 99+ in one statement, and five recent pages and posts.
        // Every list is capped, so the count does not grow with the website's size.
        ->and($count)->toBeLessThanOrEqual(41);
});

it('keeps the admin "Tổng quan" flat however many sub-categories its rows are filed under', function (): void {
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $member = User::factory()->create();
    $author = User::factory()->create();

    // Posts filed under sub-categories of the section's category: each row's section is found
    // by going up the category tree, which must not cost a query per category on the page.
    $fileUnder = function (int $count, int $from) use ($author): void {
        app(Tenancy::class)->enter(1, function () use ($count, $from, $author): void {
            $root = Taxonomy::query()->where('vocabulary', 'post_cat')->whereNull('parent_id')->firstOrFail();
            foreach (range($from, $from + $count - 1) as $i) {
                $sub = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $root->id, 'position' => $i]);
                $sub->translations()->create(['locale' => 'vi', 'name' => "Mục {$i}", 'slug' => "muc-{$i}"]);
                app(TaxonomyPaths::class)->refreshBranch($sub->id);
                $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
                $post->translations()->create(['locale' => 'vi', 'title' => "Bài mục {$i}", 'slug' => "bai-muc-{$i}", 'status' => 'draft', 'body' => ['blocks' => []]]);
                $post->assignCategories([$sub->id]);
                app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $author->fresh(), 'submit');
            }
        });
    };

    app(Tenancy::class)->enter(1, function () use ($member, $author): void {
        grantRole($member, 'reviewer', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.review', 'entries.approve']);
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        app(TaxonomyPaths::class)->refreshBranch($category->id);
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
    });

    $measure = function () use ($member): int {
        $this->actingAs($member)->get('http://a.test/admin')->assertOk(); // caches fill on the first request
        app()->forgetScopedInstances(); // a new request: the per-request memos (sections among them) start empty

        return countQueries(fn () => $this->actingAs($member)->get('http://a.test/admin')->assertOk()->assertSee(__('dash.rev_title')));
    };

    $fileUnder(1, 1);
    $one = $measure();
    $fileUnder(29, 2);
    $thirty = $measure();

    expect($thirty)->toBe($one);
});

it('renders the member\'s own account page within its query budget', function (): void {
    // Measured at 17 with two-step sign-in on, one linked account and every notification group
    // shown (16 + the top bar bell's unread count, one read on every admin page): the linked
    // accounts are one read and the notification rows add none per kind.
    $budget = 17;
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view', 'entries.review', 'forms.view', 'domains.manage']));
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $service->enable($user, $secret, totpCode($secret, -1));
    SocialIdentity::query()->create(['user_id' => $user->id, 'provider' => 'google', 'sub' => 'g-'.$user->ulid, 'email' => $user->email]);
    $user = $user->fresh();

    $this->actingAs($user)->get('http://a.test/admin/account')->assertOk(); // warm the caches a signed-in member has
    $count = countQueries(fn () => $this->actingAs($user)->get('http://a.test/admin/account?tab=prefs')->assertOk());

    expect($count)->toBeLessThanOrEqual($budget);
});
