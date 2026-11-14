<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Storefront\Support\DrawerProducts;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/*
 * The phone menu's panel beyond the menu rows: the "Menu | Sản phẩm" tabs and the product category tree
 * (read only for a site with a product list and a category under it), the text-size radios, the contact
 * block (call, Zalo, email, hours) and the social circles.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries, taxonomy_translations, taxonomies,
         product_translations, products, site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
});

/**
 * A vi site with a header (text size on), a brand and a page. With $products it also has the catalog
 * module, a products section on "Sản phẩm" (/san-pham) and one published product.
 *
 * @param  array<string, mixed>  $header  overrides of the header setting
 * @param  array<string, mixed>  $profile  columns of the site profile (social_links, business_hours)
 * @param  array<string, mixed>|null  $floating  the floating contact setting, when the site has one
 */
function dpSite(string $host = 'sp.platform.test', bool $products = true, array $header = [], array $profile = [], ?array $floating = null, string $phone = '0901 234 567'): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $host, siteName: $host, hostname: $host,
        ownerEmail: 'owner@'.$host, ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $siteId = Site::query()->firstWhere('name', $host)->id;

    app(Tenancy::class)->enter($siteId, function () use ($products, $header, $profile, $floating, $phone): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'],
            ['value' => ['name' => 'Xưởng Mẫu', 'phone' => $phone, 'email' => 'lienhe@mau.test']]);
        SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'],
            ['value' => $header + ['top_bar' => ['items' => []], 'text_size' => true, 'sticky' => true]]);
        if ($floating !== null) {
            SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'floating_contact'], ['value' => $floating]);
        }
        if ($profile !== []) {
            SiteProfile::query()->firstOrCreate([], [])->update($profile);
        }

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => 'Giới thiệu', 'paragraphs' => ['x']]]]]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $entry->id]);

        if ($products) {
            SiteModule::query()->updateOrCreate(['module_key' => 'catalog'], ['enabled' => true]);
            $root = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
            $root->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm', 'slug' => 'san-pham']);
            app(TaxonomyPaths::class)->refreshBranch($root->id);
            ContentSection::query()->updateOrCreate(['key' => 'products'], ['label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $root->id]);
            app(RouteSync::class)->syncTaxonomy($root, 'vi', 'san-pham');
            $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
            $product->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm 1', 'slug' => 'sp-1']);
        }
    });

    return $siteId;
}

/**
 * A category under the site's products section (or under $parent), with its name in vi and its path set.
 *
 * @param  array<string, string>  $names  locale => name; the slug is derived per locale
 */
function dpCategory(int $siteId, array $names, ?int $parent = null, string $slug = '', bool $route = true): int
{
    return app(Tenancy::class)->enter($siteId, function () use ($names, $parent, $slug, $route): int {
        $parent ??= (int) ContentSection::query()->where('key', 'products')->value('taxonomy_id');
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $parent, 'position' => (int) Taxonomy::query()->where('parent_id', $parent)->count()]);
        foreach ($names as $locale => $name) {
            $own = ($slug !== '' ? $slug : 'danh-muc-'.$category->id).($locale === 'vi' ? '' : '-'.$locale);
            $category->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => $own]);
            if ($route) {
                app(RouteSync::class)->syncTaxonomy($category, $locale, $own);   // the address a menu link to it would need
            }
        }
        app(TaxonomyPaths::class)->refreshBranch($category->id);

        return $category->id;
    });
}

/** The product tab's panel of a page: from its tab to the language row. */
function dpProductsPane(string $html): string
{
    $from = (int) strpos($html, 'id="msm-pane-products"');
    $to = (int) strpos($html, 'sf-drawer__tools', $from);

    return substr($html, $from, ($to > $from ? $to : strlen($html)) - $from);
}

it('shows no tabs and no product list on a site without products', function (): void {
    dpSite(products: false);

    $html = $this->get('http://sp.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)->toContain('id="mobile-menu"')
        ->not->toContain('role="tablist"')->not->toContain('msm-pane-products')
        ->not->toContain('role="tabpanel"');
});

it('shows no tabs when the products section has no category under it', function (): void {
    dpSite();

    expect($this->get('http://sp.platform.test/gioi-thieu')->assertOk()->getContent())
        ->not->toContain('role="tablist"')->not->toContain('msm-pane-products');
});

it('shows the Menu and Sản phẩm tabs with the category tree and the link to every product', function (): void {
    $siteId = dpSite();
    $jig = dpCategory($siteId, ['vi' => 'Jig & Đồ gá'], slug: 'jig-do-ga');
    dpCategory($siteId, ['vi' => 'Jig hàn'], $jig, 'jig-han');
    dpCategory($siteId, ['vi' => 'Khuôn mẫu'], slug: 'khuon-mau');

    $html = $this->get('http://sp.platform.test/gioi-thieu')->assertOk()->getContent();
    $pane = dpProductsPane($html);

    expect($html)
        ->toContain('<div class="sf-drawer__tabs" role="tablist" aria-label="Nội dung menu" data-drawer-tabs>')
        ->toMatch('#id="msm-tab-menu" aria-controls="msm-pane-menu" aria-selected="true">Menu</button>#')
        ->toMatch('#id="msm-tab-products" aria-controls="msm-pane-products" aria-selected="false" tabindex="-1">Sản phẩm</button>#')
        ->toContain('role="tabpanel" id="msm-pane-products" aria-labelledby="msm-tab-products" hidden');
    expect($pane)
        ->toContain('Jig &amp; Đồ gá')
        ->toContain('Xem tất cả Jig &amp; Đồ gá')           // a top row's own page is its first row inside
        ->toContain('href="/jig-han"')
        ->toContain('href="/khuon-mau"')
        ->toMatch('#<a class="sf-drawer__all" href="/san-pham"><span>Xem tất cả sản phẩm</span>#')
        ->not->toMatch('#/\d+["\#?]#');                      // no raw id in any address
});

it('marks the category the page is on and keeps the group’s own row unmarked', function (): void {
    $siteId = dpSite();
    $jig = dpCategory($siteId, ['vi' => 'Jig & Đồ gá'], slug: 'jig-do-ga');
    $weld = dpCategory($siteId, ['vi' => 'Jig hàn'], $jig, 'jig-han');
    app(Tenancy::class)->enter($siteId, function () use ($weld): void {
        $tree = app(DrawerProducts::class)->tree('vi', 'vi', '/jig-han');
        expect($tree['items'][0]['children'][1])->toMatchArray(['label' => 'Jig hàn', 'url' => '/jig-han', 'active' => true])
            ->and($tree['items'][0]['children'][0]['active'])->toBeFalse()
            ->and($weld)->toBeInt();
    });
});

it('hides the product tab when the header switches it off', function (): void {
    $siteId = dpSite(header: ['drawer_products' => false]);
    dpCategory($siteId, ['vi' => 'Khuôn mẫu'], slug: 'khuon-mau');

    expect($this->get('http://sp.platform.test/gioi-thieu')->assertOk()->getContent())
        ->not->toContain('role="tablist"')->not->toContain('Khuôn mẫu');
});

it('lists twelve or more groups with two child levels and never cuts a long name', function (): void {
    $siteId = dpSite();
    $long = 'Máy tính xách tay, máy tính bảng và thiết bị văn phòng dùng cho doanh nghiệp vừa và nhỏ';
    $top = null;
    foreach (range(1, 13) as $i) {
        $id = dpCategory($siteId, ['vi' => $i === 1 ? $long : "Nhóm {$i}"], slug: "nhom-{$i}");
        $top ??= $id;
    }
    $mid = dpCategory($siteId, ['vi' => 'Máy tính xách tay'], $top, 'may-tinh-xach-tay');
    dpCategory($siteId, ['vi' => 'Laptop đồ họa, dựng phim và chơi game cấu hình cao'], $mid, 'laptop-do-hoa');

    $pane = dpProductsPane($this->get('http://sp.platform.test/gioi-thieu')->assertOk()->getContent());

    foreach (range(2, 13) as $i) {
        expect($pane)->toContain("Nhóm {$i}");
    }
    expect($pane)->toContain($long)->toContain('Máy tính xách tay')->toContain('Laptop đồ họa, dựng phim và chơi game cấu hình cao')
        ->not->toContain('…')->not->toContain('text-overflow');
});

it('reads the whole tree in two queries however many categories there are', function (): void {
    $siteId = dpSite();
    $first = dpCategory($siteId, ['vi' => 'Một'], slug: 'mot');
    foreach (range(2, 30) as $i) {
        dpCategory($siteId, ['vi' => "Nhóm {$i}"], $i % 2 === 0 ? $first : null, "nhom-{$i}");
    }

    $reads = app(Tenancy::class)->enter($siteId, function (): array {
        $tree = app(DrawerProducts::class);
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $tree->tree('vi', 'vi', '/');

        // The gate before the tree (section, entitlement, product, list address) is counted by the page budgets;
        // here only the tree's own reads: the categories (the only read that asks for a depth) and their names with routes.
        return array_values(array_filter($sql, fn (string $q): bool => str_contains($q, 'nlevel(') || str_contains($q, 'taxonomy_translations" as "tt"')));
    });

    expect($reads)->toHaveCount(2);
});

it('leaves out a category that has no route or no name, and what is under it', function (): void {
    $siteId = dpSite();
    $bare = dpCategory($siteId, ['vi' => 'Chưa có địa chỉ'], slug: 'chua-co', route: false);
    dpCategory($siteId, ['vi' => 'Con của nó'], $bare, 'con-cua-no');
    $good = dpCategory($siteId, ['vi' => 'Có địa chỉ'], slug: 'co-dia-chi');
    dpCategory($siteId, ['vi' => 'Con không địa chỉ'], $good, 'con-khong', route: false);
    dpCategory($siteId, ['vi' => 'Con có địa chỉ'], $good, 'con-co');

    $pane = dpProductsPane($this->get('http://sp.platform.test/gioi-thieu')->assertOk()->getContent());

    expect($pane)->toContain('Có địa chỉ')->toContain('Con có địa chỉ')
        ->not->toContain('Chưa có địa chỉ')->not->toContain('Con của nó')->not->toContain('Con không địa chỉ');
});

it('shows the default language’s name where the page language’s is empty, and a link that resolves', function (): void {
    $siteId = dpSite();
    app(Tenancy::class)->enter($siteId, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $root = Taxonomy::query()->findOrFail(ContentSection::query()->where('key', 'products')->value('taxonomy_id'));
        $root->translations()->create(['locale' => 'en', 'name' => 'Products', 'slug' => 'products']);
        app(RouteSync::class)->syncTaxonomy($root, 'en', 'products');
    });
    dpCategory($siteId, ['vi' => 'Khuôn mẫu', 'en' => ''], slug: 'khuon-mau');

    app(Tenancy::class)->enter($siteId, function (): void {
        $tree = app(DrawerProducts::class)->tree('en', 'vi', '/');
        expect($tree['items'][0]['label'])->toBe('Khuôn mẫu')->and($tree['items'][0]['url'])->toBe('/khuon-mau');
    });
    $this->get('http://sp.platform.test/khuon-mau')->assertOk();   // the drawer's link opens a page
});

it('never shows another site’s categories', function (): void {
    $a = dpSite('mot.platform.test');
    dpCategory($a, ['vi' => 'Chỉ của xưởng một'], slug: 'chi-mot');
    $b = dpSite('hai.platform.test');
    dpCategory($b, ['vi' => 'Chỉ của xưởng hai'], slug: 'chi-hai');

    $one = $this->get('http://mot.platform.test/gioi-thieu')->assertOk()->getContent();
    $two = $this->get('http://hai.platform.test/gioi-thieu')->assertOk()->getContent();

    expect(dpProductsPane($one))->toContain('Chỉ của xưởng một')->not->toContain('Chỉ của xưởng hai')
        ->and(dpProductsPane($two))->toContain('Chỉ của xưởng hai')->not->toContain('Chỉ của xưởng một');
});

it('writes the tree in the page’s language with the default language where a name is missing', function (): void {
    $siteId = dpSite();
    app(Tenancy::class)->enter($siteId, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $root = Taxonomy::query()->findOrFail(ContentSection::query()->where('key', 'products')->value('taxonomy_id'));
        $root->translations()->create(['locale' => 'en', 'name' => 'Products', 'slug' => 'products']);
        app(RouteSync::class)->syncTaxonomy($root, 'en', 'products');
        $entry = Entry::query()->findOrFail(EntryTranslation::query()->where('slug', 'gioi-thieu')->value('entry_id'));
        $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'published',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => 'About', 'paragraphs' => ['x']]]]]]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/about', 'entry_id' => $entry->id]);
        Product::query()->firstOrFail()->translations()->create(['locale' => 'en', 'name' => 'Product 1', 'slug' => 'product-1']);
    });
    dpCategory($siteId, ['vi' => 'Khuôn mẫu', 'en' => 'Moulds'], slug: 'khuon-mau');
    dpCategory($siteId, ['vi' => 'Chi tiết máy'], slug: 'chi-tiet-may');   // no English name

    $pane = dpProductsPane($this->get('http://sp.platform.test/en/about')->assertOk()->getContent());

    expect($pane)
        ->toContain('Moulds')->toContain('href="/en/khuon-mau-en"')
        ->toContain('Chi tiết máy')->toContain('href="/chi-tiet-may"')   // the default language's name and address
        ->toContain('View all products')->toContain('href="/en/products"');
});

it('draws the text size as a group of three radios with their names, only when the site turns it on', function (): void {
    dpSite(products: false);

    $html = $this->get('http://sp.platform.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<fieldset class="sf-drawer__fs" data-fs-set>')
        ->toContain('<legend>Cỡ chữ</legend>')
        ->toMatch('#<input class="sf-drawer__fs-in" type="radio" name="sf-fs" value="m" data-fs-radio aria-label="Vừa" title="Vừa" checked>#')
        ->toMatch('#type="radio" name="sf-fs" value="l" data-fs-radio aria-label="Lớn"#')
        ->toMatch('#type="radio" name="sf-fs" value="xl" data-fs-radio aria-label="Cực lớn"#')
        ->and(substr_count($html, 'data-fs-radio'))->toBe(3)
        ->and(substr_count($html, '<span aria-hidden="true">A</span>'))->toBe(3);
});

it('draws no text size group when the site did not turn it on', function (): void {
    dpSite(products: false, header: ['text_size' => false]);

    expect($this->get('http://sp.platform.test/gioi-thieu')->assertOk()->getContent())
        ->not->toContain('sf-drawer__fs"')->not->toContain('data-fs-radio');
});

it('shows Zalo only when the floating contact has a Zalo channel, with the button’s own link', function (): void {
    dpSite('co.platform.test', products: false, floating: ['enabled' => true, 'theme' => 'horizontal', 'position' => 'right',
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678'], ['key' => 'zalo', 'on' => true, 'value' => '0912345678']]]);
    dpSite('khong.platform.test', products: false, floating: ['enabled' => true, 'theme' => 'horizontal', 'position' => 'right',
        'channels' => [['key' => 'phone', 'on' => true, 'value' => '0912345678']]]);

    expect($this->get('http://co.platform.test/gioi-thieu')->assertOk()->getContent())
        ->toMatch('#<a class="sf-drawer__zalo" href="https://zalo\.me/\d+" rel="noopener">#')->toContain('Chat Zalo')
        ->and($this->get('http://khong.platform.test/gioi-thieu')->assertOk()->getContent())
        ->not->toContain('sf-drawer__zalo');
});

it('draws a second-level parent as a disclosure with its own page first and the grandchildren one step in', function (): void {
    $siteId = dpSite();
    $top = dpCategory($siteId, ['vi' => 'Máy tính'], slug: 'may-tinh');
    $mid = dpCategory($siteId, ['vi' => 'Laptop'], $top, 'laptop');
    dpCategory($siteId, ['vi' => 'Laptop mỏng nhẹ'], $mid, 'laptop-mong-nhe');
    dpCategory($siteId, ['vi' => 'Máy in'], $top, 'may-in');                         // a second-level row with no children
    $other = dpCategory($siteId, ['vi' => 'Khuôn mẫu'], slug: 'khuon-mau');
    dpCategory($siteId, ['vi' => 'Khuôn ép'], $other, 'khuon-ep');
    $kids = dpCategory($siteId, ['vi' => 'Khuôn dập'], $other, 'khuon-dap');
    dpCategory($siteId, ['vi' => 'Khuôn dập nguội'], $kids, 'khuon-dap-nguoi');

    $pane = dpProductsPane($this->get('http://sp.platform.test/gioi-thieu')->assertOk()->getContent());
    preg_match_all('#<button type="button" class="[^"]*sf-nav__toggle[^"]*"[^>]*aria-expanded="(true|false)" aria-controls="([^"]+)"#', $pane, $buttons);
    preg_match_all('#<div class="sf-nav__panel" id="([^"]+)"#', $pane, $panels);

    expect($buttons[2])->toHaveCount(4)                                              // 2 groups + 2 second-level parents
        ->and($buttons[2])->toBe(array_values(array_unique($buttons[2])))             // ids unique
        ->and($panels[1])->toBe($buttons[2])                                         // every button points at a panel
        ->and($buttons[1])->each->toBe('false')
        ->and($pane)->toContain('Xem tất cả Laptop')->toContain('href="/laptop"')
        ->toContain('Xem tất cả Khuôn dập')->toContain('href="/khuon-dap"')
        ->toContain('href="/laptop-mong-nhe"')
        ->toMatch('#<a class="sf-nav__sublink" href="/may-in">Máy in</a>#');         // a leaf stays a link
    // "Xem tất cả Laptop" comes first inside the Laptop panel
    expect(strpos($pane, 'Xem tất cả Laptop'))->toBeLessThan(strpos($pane, 'Laptop mỏng nhẹ'));
});

it('opens the way to the category the page is on and marks it', function (): void {
    $siteId = dpSite();
    $top = dpCategory($siteId, ['vi' => 'Máy tính'], slug: 'may-tinh');
    $mid = dpCategory($siteId, ['vi' => 'Laptop'], $top, 'laptop');
    $leaf = dpCategory($siteId, ['vi' => 'Laptop mỏng nhẹ'], $mid, 'laptop-mong-nhe');
    dpCategory($siteId, ['vi' => 'Máy in'], $top, 'may-in');
    $far = dpCategory($siteId, ['vi' => 'Khuôn mẫu'], slug: 'khuon-mau');
    dpCategory($siteId, ['vi' => 'Khuôn ép'], $far, 'khuon-ep');
    app(Tenancy::class)->enter($siteId, function () use ($leaf): void {
        app(RouteSync::class)->syncTaxonomy(Taxonomy::query()->findOrFail($leaf), 'vi', 'laptop-mong-nhe');
    });

    $pane = dpProductsPane($this->get('http://sp.platform.test/laptop-mong-nhe')->assertOk()->getContent());

    expect($pane)
        ->toMatch('#<li class="sf-nav__item sf-nav__item--parent is-open" data-disclosure>\s*<button[^>]*is-trail"[^>]*aria-current="true" aria-expanded="true"[^>]*><span>Máy tính#')
        ->toMatch('#<button[^>]*is-trail"[^>]*aria-expanded="true"[^>]*><span>Laptop</span>#')
        ->toMatch('#<a class="sf-nav__sublink" href="/laptop-mong-nhe" aria-current="page">Laptop mỏng nhẹ</a>#')
        ->toMatch('#aria-expanded="false" aria-controls="[^"]+"><span>Khuôn mẫu#')            // the other group stays shut
        ->and(substr_count($pane, 'aria-current="page"'))->toBe(1);
});

it('draws the Zalo mark in Zalo blue only when the Zalo button exists', function (): void {
    $floating = static fn (array $channels): array => ['enabled' => true, 'theme' => 'horizontal', 'position' => 'right', 'channels' => $channels];
    dpSite('co.platform.test', products: false, floating: $floating([['key' => 'zalo', 'on' => true, 'value' => '0912345678']]));
    dpSite('khong.platform.test', products: false, floating: $floating([['key' => 'phone', 'on' => true, 'value' => '0912345678']]));

    expect($this->get('http://co.platform.test/gioi-thieu')->assertOk()->getContent())
        ->toMatch('#<a class="sf-drawer__zalo"[^>]*><svg class="sf-drawer__zmark" width="20" height="20"[^>]*><path fill="\#0068FF"[^>]*/><path fill="\#fff"[^>]*/></svg><span>Chat Zalo</span>#')
        ->and($this->get('http://khong.platform.test/gioi-thieu')->assertOk()->getContent())
        ->not->toContain('sf-drawer__zmark')->not->toContain('#0068FF');
});

it('shows the opening hours line only when the site set them', function (): void {
    dpSite('gio.platform.test', products: false, profile: ['business_hours' => ['days' => 'mon-sat', 'opens' => '07:30', 'closes' => '17:30']]);
    dpSite('khong.platform.test', products: false);

    expect($this->get('http://gio.platform.test/gioi-thieu')->assertOk()->getContent())
        ->toContain('sf-drawer__line--hours')->toContain('07:30')
        ->and($this->get('http://khong.platform.test/gioi-thieu')->assertOk()->getContent())
        ->not->toContain('sf-drawer__line--hours');
});

it('draws no social row without profiles and one named link per profile with them', function (): void {
    dpSite('khong.platform.test', products: false);
    dpSite('co.platform.test', products: false, profile: ['social_links' => [
        'facebook' => 'https://facebook.com/comau', 'linkedin' => 'https://www.linkedin.com/company/comau',
        'youtube' => 'https://youtube.com/@comau', 'zalo' => '0912345678', 'tiktok' => 'https://tiktok.com/@comau', 'x' => 'https://x.com/comau',
    ]]);

    $html = $this->get('http://co.platform.test/gioi-thieu')->assertOk()->getContent();
    $row = substr($html, (int) strpos($html, 'sf-drawer__socials'), 9000);

    expect($this->get('http://khong.platform.test/gioi-thieu')->assertOk()->getContent())->not->toContain('sf-drawer__socials')
        ->and(substr_count($html, 'class="sf-drawer__social"'))->toBe(6)
        ->and($row)->toContain('aria-label="Facebook của Xưởng Mẫu"')->toContain('aria-label="YouTube của Xưởng Mẫu"')
        ->toContain('aria-label="Zalo của Xưởng Mẫu"')->toContain('aria-label="X của Xưởng Mẫu"')
        ->toContain('rel="noopener"');
});

it('keeps the drawer’s order: tabs, menu, language and text size, then the contact block with the socials last', function (): void {
    $siteId = dpSite('thu.platform.test', profile: ['social_links' => ['facebook' => 'https://facebook.com/comau']]);
    dpCategory($siteId, ['vi' => 'Khuôn mẫu'], slug: 'khuon-mau');

    $html = $this->get('http://thu.platform.test/gioi-thieu')->assertOk()->getContent();
    $at = fn (string $needle): int => (int) strpos($html, $needle);

    expect($at('data-drawer-tabs'))->toBeLessThan($at('id="msm-pane-menu"'))
        ->and($at('id="msm-pane-menu"'))->toBeLessThan($at('id="msm-pane-products"'))
        ->and($at('id="msm-pane-products"'))->toBeLessThan($at('sf-drawer__fs"'))
        ->and($at('sf-drawer__fs"'))->toBeLessThan($at('sf-drawer__contact'))
        ->and($at('sf-drawer__call'))->toBeLessThan($at('sf-drawer__socials'))
        ->and($html)->not->toContain('type="search"');
});

it('puts no tab bar into the page without scripts and shows both panes stacked', function (): void {
    dpSite();

    expect($this->get('http://sp.platform.test/gioi-thieu')->assertOk()->getContent())
        ->toContain('.sf-drawer__pane[hidden]{display:block}.sf-burger,.sf-fs,.sf-drawer__fs,.sf-drawer__tabs{display:none}');
});
