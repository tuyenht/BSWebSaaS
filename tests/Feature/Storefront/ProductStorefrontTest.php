<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Product storefront — U1-c S2 read path. A product route resolves to ResolvedProduct (a thin Cms
 * reference) and StorefrontController loads the Product (Catalog) + renders the product layout.
 * Cms never loads Product (acyclic); the storefront enforces published visibility at load.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, product_translations, product_images, products,
         entry_translations, entries, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
});

/** Provision An Vinh, pin the catalog to base=san-pham, seed one product; return its route path. */
function seedStorefrontProduct(string $slug, string $status = 'published'): string
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    $site = Site::query()->where('name', 'An Vinh')->firstOrFail();

    return app(Tenancy::class)->enter($site->id, function () use ($slug, $status): string {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'permalinks', 'key' => 'products'],
            ['value' => ['pattern' => 'base', 'base' => 'san-pham']],
        );

        $product = Product::query()->create(['status' => $status]);
        $product->translations()->create([
            'locale' => 'vi', 'name' => 'Jig kiểm VF7', 'slug' => $slug,
            'description' => '<p>Đồ gá kiểm tra cụm taplo.</p>',
        ]);

        return app(RouteSync::class)->syncProduct($product->id, null, 'vi', $slug);
    });
}

it('renders a published product detail page at its base-pinned route within budget', function (): void {
    expect(seedStorefrontProduct('jig-vf7'))->toBe('/san-pham/jig-vf7');

    $count = countQueries(function (): void {
        $html = $this->get('http://anvinh.test/san-pham/jig-vf7')->assertOk()->getContent();

        expect($html)
            ->toContain('<h1 class="product__title">Jig kiểm VF7</h1>')
            ->toContain('Đồ gá kiểm tra cụm taplo')                                          // description HTML rendered
            ->toContain('<link rel="canonical" href="http://anvinh.test/san-pham/jig-vf7">') // canonical from the stored route
            ->toContain('property="og:type" content="product"');
    });

    // Product load (+ eager translations & images.media), route resolve, nav, brand — no N+1.
    expect($count)->toBeLessThanOrEqual(16);
});

it('404s a product route whose product is not published (visibility enforced at load)', function (): void {
    expect(seedStorefrontProduct('jig-draft', 'draft'))->toBe('/san-pham/jig-draft');

    // The route exists (syncProduct is status-agnostic) but the storefront load filters published.
    $this->get('http://anvinh.test/san-pham/jig-draft')->assertNotFound();
});

/** Provision + wire the catalog index at /san-pham (products section + list route) with N products. */
function seedCatalogIndex(int $productCount = 2): void
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    $site = Site::query()->where('name', 'An Vinh')->firstOrFail();

    app(Tenancy::class)->enter($site->id, function () use ($productCount): void {
        // The catalog category (post_cat) + its /san-pham list route + the section that binds it.
        $sanPham = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $sanPham->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm', 'slug' => 'san-pham']);
        ContentSection::query()->create(['key' => 'products', 'label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $sanPham->id]);
        app(RouteSync::class)->syncTaxonomy($sanPham, 'vi', 'san-pham');

        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'permalinks', 'key' => 'products'],
            ['value' => ['pattern' => 'base', 'base' => 'san-pham']],
        );

        for ($i = 1; $i <= $productCount; $i++) {
            $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
            $product->translations()->create(['locale' => 'vi', 'name' => "Sản phẩm {$i}", 'slug' => "sp-{$i}"]);
            app(RouteSync::class)->syncProduct($product->id, null, 'vi', "sp-{$i}");
        }
    });
}

it('lists published products at the catalog category index (/san-pham)', function (): void {
    seedCatalogIndex(2);

    $html = $this->get('http://anvinh.test/san-pham')->assertOk()->getContent();

    // The catalog category renders products (not entries), each linking to its base-pinned route.
    expect($html)
        ->toContain('Sản phẩm 1')->toContain('href="/san-pham/sp-1"')
        ->toContain('Sản phẩm 2')->toContain('href="/san-pham/sp-2"');
});

it('emits Product JSON-LD on the detail page — name, text description, url, brand; no offers', function (): void {
    seedStorefrontProduct('jig-vf7');

    // Give the tenant a brand so the LD carries it.
    $site = Site::query()->firstWhere('name', 'An Vinh');
    app(Tenancy::class)->enter($site->id, function (): void {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'general', 'key' => 'brand'],
            ['value' => ['name' => 'Cơ Khí Chính Xác An Vinh']],
        );
    });

    $html = $this->get('http://anvinh.test/san-pham/jig-vf7')->assertOk()->getContent();

    expect($html)->toContain('"@type":"Product"');

    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
    $productLd = collect($matches[1])
        ->map(fn (string $json) => json_decode($json, true))
        ->firstWhere('@type', 'Product');

    expect($productLd)->not->toBeNull();
    expect($productLd['name'])->toBe('Jig kiểm VF7');
    expect($productLd['description'])->toBe('Đồ gá kiểm tra cụm taplo.'); // stripped to plain text
    expect($productLd['url'])->toBe('http://anvinh.test/san-pham/jig-vf7');
    expect($productLd['brand'])->toBe(['@type' => 'Brand', 'name' => 'Cơ Khí Chính Xác An Vinh']);
    expect($productLd)->not->toHaveKey('offers'); // brochure catalog — nothing is sold online
});

it('opens a product the admin has just published — page and catalog link alike', function (): void {
    seedCatalogIndex(0);
    $site = Site::query()->where('name', 'An Vinh')->firstOrFail();
    $owner = User::query()->where('email', 'owner@anvinh.test')->firstOrFail();

    app(Tenancy::class)->enter($site->id, function () use ($owner): void {
        Livewire::actingAs($owner)
            ->test(Editor::class)
            ->set('name', 'Jig hàn khung mới')
            ->set('slug', 'jig-han-khung-moi')
            ->set('status', 'published')
            ->call('save')
            ->assertHasNoErrors();
    });

    $this->get('http://anvinh.test/san-pham/jig-han-khung-moi')->assertOk()->assertSee('Jig hàn khung mới');
    expect($this->get('http://anvinh.test/san-pham')->assertOk()->getContent())->toContain('href="/san-pham/jig-han-khung-moi"');
});
