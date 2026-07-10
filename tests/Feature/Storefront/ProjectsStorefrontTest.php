<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Actions\SeedAnVinhContent;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/*
 * Cơ Khí Chính Xác An Vinh storefront — collection and page smoke tests.
 * Verifies that the seeded content is reachable via HTTP and renders correctly:
 * category lists (/san-pham, /du-an, /dich-vu, /tin-tuc), new pages (/gioi-thieu, /doi-tac),
 * home body (entry_grid cards with resolved media URLs), contact NAP, and detail pages
 * with breadcrumb JSON-LD. All within the Query Budget (N+1 invariant).
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, routes, entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
});

function seedAnVinhStorefront(): void
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $site = Site::query()->firstWhere('name', 'An Vinh');
    app(Tenancy::class)->enter($site->id, fn () => app(SeedAnVinhContent::class)->handle());
}

it('lists the projects at /du-an within budget', function (): void {
    seedAnVinhStorefront();

    $count = countQueries(function (): void {
        $html = $this->get('http://anvinh.test/du-an')->assertOk()->getContent();

        expect($html)
            ->toContain('class="category__title">Dự án')
            ->toContain('Dây chuyền lắp ráp tự động')
            ->toContain('href="/du-an/day-chuyen-lap-rap-tu-dong"');
    });

    // MenuResolver batches route+title lookups regardless of item count; the An Vinh nav
    // (7 items including entry-type links for /gioi-thieu and /doi-tac) still costs the
    // same 1 batched route query as the original 3-item nav. The header brand logo adds one
    // small per-page lookup (the 'brand' site setting) on every storefront route; the catalog probe
    // (is this the product-index category?) adds one more constant lookup → budget 19.
    expect($count)->toBeLessThanOrEqual(19);
});

it('renders a project detail with breadcrumb JSON-LD', function (): void {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/du-an/day-chuyen-lap-rap-tu-dong')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Dây chuyền lắp ráp tự động</title>')
        ->toContain('class="post__breadcrumb"')
        ->toContain('href="/du-an"')
        ->toContain('"@type":"BreadcrumbList"')
        ->toContain('"name":"Dự án"');
});

it('lists the services at /dich-vu within budget', function (): void {
    seedAnVinhStorefront();

    $count = countQueries(function (): void {
        $html = $this->get('http://anvinh.test/dich-vu')->assertOk()->getContent();

        expect($html)
            ->toContain('class="category__title">Dịch vụ')
            ->toContain('Thiết kế Jig')
            ->toContain('href="/dich-vu/thiet-ke-jig-do-ga"');
    });

    expect($count)->toBeLessThanOrEqual(18); // +1 vs a bare category: the constant catalog-index probe
});

it('renders a service detail page with breadcrumb', function (): void {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/dich-vu/thiet-ke-jig-do-ga')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Thiết kế Jig &amp; Đồ gá</title>')
        ->toContain('class="post__breadcrumb"')
        ->toContain('href="/dich-vu"')
        ->toContain('"@type":"BreadcrumbList"')
        ->toContain('"name":"Dịch vụ"');
});

it('lists the products at /san-pham', function (): void {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/san-pham')->assertOk()->getContent();

    expect($html)
        ->toContain('class="category__title">Sản phẩm')
        ->toContain('Trục máy CNC')
        ->toContain('href="/san-pham/truc-may-cnc"');
});

it('renders a product detail page with breadcrumb', function (): void {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/san-pham/truc-may-cnc')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Trục máy CNC</title>')
        ->toContain('class="post__breadcrumb"')
        ->toContain('href="/san-pham"')
        ->toContain('"name":"Sản phẩm"');
});

it('renders /gioi-thieu with about content', function (): void {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Giới thiệu</title>')
        ->toContain('<meta name="description" content="Giới thiệu Cơ Khí Chính Xác An Vinh')  // §5: page has a real meta description, not empty
        ->toContain('?w=1280')         // about image resolved at the whitelisted content-image width — the Worker serves it (a non-whitelisted ?w would 400)
        ->not->toContain('?w=800')     // regression guard: the width must never be the rejected 800
        ->toContain('ISO 9001:2015')
        ->toContain('Tầm Nhìn');   // 'Tầm Nhìn' in body
});

it('renders /doi-tac with partner names and testimonials', function (): void {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/doi-tac')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Đối tác</title>')
        ->toContain('VinFast')
        ->toContain('Hutchinson')
        ->toContain('Vinmanutech');
});

it('renders /lien-he with real NAP', function (): void {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/lien-he')->assertOk()->getContent();

    expect($html)
        ->toContain('0915 66 83 83')
        ->toContain('quybv@ckanvinh.vn');
});

it('renders the home page with An Vinh heading and resolved media URLs in entry_grid cards', function (): void {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/')->assertOk()->getContent();

    // Hero heading
    expect($html)->toContain('Cơ Khí Chính Xác An Vinh');

    // entry_grid renders card images via MediaUrlResolver (key has / → resolved to ?w= URL)
    // EntryGridComponent::CARD_WIDTH = 640 → ?w=640 appended by cloudflare driver
    expect($html)->toContain('?w=');

    // Featured products now render via a data-bound product_grid (swapped from the old san-pham
    // entry_grid). The seed's catalog is still posts, so the grid is empty and shows its section
    // heading; product-card population is covered by ProductGridComponentTest.
    expect($html)->toContain('Sản phẩm tiêu biểu');

    // Header carries the brand logo (server-resolved from the 'brand' site setting)
    expect($html)->toContain('site-header__logo')->toContain('an-vinh/brand/logo-');
});

it('news posts are listed at /tin-tuc', function (): void {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/tin-tuc')->assertOk()->getContent();

    expect($html)
        ->toContain('class="category__title">Tin tức')
        ->toContain('An Vinh tổ chức team building 2024');
});

it('emits og:image from brand setting on the home page', function (): void {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/')->assertOk()->getContent();

    // SeedAnVinhContent writes og_image (KEY_ABOUT at w=1280) to the brand site setting;
    // head.blade.php emits <meta property="og:image"> when data_get($brand,'og_image') is set.
    expect($html)->toContain('<meta property="og:image" content="http');
});

it('category list /san-pham has non-empty meta description via brand fallback', function (): void {
    seedAnVinhStorefront();

    $html = $this->get('http://anvinh.test/san-pham')->assertOk()->getContent();

    // renderCategory passes no seo description; the head partial falls back to brand.description.
    // The tag must exist and its content must not be empty.
    expect($html)
        ->toContain('<meta name="description" content="')
        ->not->toContain('<meta name="description" content="">');
});
