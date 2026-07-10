<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

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
