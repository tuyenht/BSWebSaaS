<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Forms\Models\Form;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteSetting;

/*
 * The admin's preview of a product: the real storefront product page of a product of the current
 * site, any status, for a member who manages products — never cached or indexed, no referrer, the
 * storefront's CSP with framing by the admin only, every link opening outside the frame, the lead
 * forms switched off. Everything else answers 404 (another site's product, the Trash, no catalog in
 * the plan, a language the site does not have), so a preview never tells that a product exists.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    config(['services.turnstile.site_key' => '']);
    foreach ([1, 2] as $site) {
        app(Tenancy::class)->enter($site, fn () => SiteModule::query()->create(['module_key' => 'catalog', 'enabled' => true]));
    }
});

/** Start the next request from a clean container, as every request does in production. */
function previewFresh(): void
{
    app()->forgetScopedInstances();
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
    app(TeamContext::class)->forget();
}

/** A product of $site in a category, with its route; returns its ulid. */
function previewProduct(int $site = 1, string $status = 'draft', string $name = 'Đồ gá kiểm định', string $slug = 'do-ga-kiem-dinh'): string
{
    return app(Tenancy::class)->enter($site, function () use ($status, $name, $slug): string {
        $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá chuyên dụng', 'slug' => 'do-ga']);
        $product = Product::query()->create(['status' => $status, 'published_at' => $status === 'published' ? now() : null]);
        $product->translations()->create([
            'locale' => 'vi', 'name' => $name, 'slug' => $slug,
            'summary' => 'Đồ gá kiểm tra cụm bảng điều khiển.', 'description' => '<p>Kiểm tra kích thước từng chi tiết.</p>',
        ]);
        $product->taxonomies()->attach($category->id, ['site_id' => $product->site_id, 'position' => 0]);
        if ($status === 'published') {
            app(RouteSync::class)->syncProduct($product->id, null, 'vi', $slug);
        }

        return $product->ulid;
    });
}

/** A member of $site holding $permissions. */
function previewMember(array $permissions, int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'role-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/** The site's contact form, drawn after every product page (the end-of-page slot). */
function previewFormAfterProducts(int $site = 1): void
{
    app(Tenancy::class)->enter($site, function (): void {
        Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Gửi yêu cầu báo giá'], 'status' => 'active'])->fields()->createMany([
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1],
            ['key' => 'message', 'type' => 'textarea', 'label' => ['vi' => 'Lời nhắn'], 'is_required' => true, 'sort_order' => 2],
        ]);
        SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'layout_blocks'], ['value' => [
            'after_product' => ['vi' => ['blocks' => [['type' => 'form', 'props' => ['form_key' => 'contact']]]]],
        ]]);
    });
}

it('shows a draft’s real product page to a member who manages products, never cached, indexed or framed by others', function (): void {
    $ulid = previewProduct();
    $manager = previewMember(['catalog.view', 'catalog.manage']);

    $response = $this->actingAs($manager)->get("http://a.test/admin/catalog/{$ulid}/preview")->assertOk();
    $html = (string) $response->getContent();

    expect($html)
        ->toContain('<h1 class="product__title">Đồ gá kiểm định</h1>')
        ->toContain('Kiểm tra kích thước từng chi tiết.')
        ->toContain('<p class="product__group">Đồ gá chuyên dụng</p>')
        ->toContain('<p class="sf-preview" role="note">Bản xem trước — khách chưa thấy trang này.</p>')
        ->toContain('<meta name="robots" content="noindex, nofollow">')
        ->toContain('<base target="_blank">')
        ->toContain('<a class="sf-skip" href="#noi-dung" target="_self">')
        ->not->toContain('rel="canonical"')
        ->not->toContain('hreflang=')
        ->not->toContain('property="og:')
        ->not->toContain('application/ld+json');

    $cache = (string) $response->headers->get('Cache-Control');
    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($cache)->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($csp)->toContain("default-src 'self'")->toContain("form-action 'self'")->toEndWith("; frame-ancestors 'self'");
});

it('says a published product’s preview is the page on the website, and still keeps it out of search engines', function (): void {
    $ulid = previewProduct(status: 'published');
    $manager = previewMember(['catalog.view', 'catalog.manage']);

    $html = (string) $this->actingAs($manager)->get("http://a.test/admin/catalog/{$ulid}/preview")->assertOk()->getContent();

    expect($html)->toContain('<p class="sf-preview" role="note">Bản xem trước — trang này đang hiện trên website.</p>')
        ->toContain('<meta name="robots" content="noindex, nofollow">')
        ->not->toContain('rel="canonical"');
});

it('refuses a member who only views products, and sends a visitor who is not signed in to the sign-in page', function (): void {
    $ulid = previewProduct();
    $viewer = previewMember(['catalog.view']);

    $this->actingAs($viewer)->get("http://a.test/admin/catalog/{$ulid}/preview")->assertForbidden();

    auth()->logout();
    previewFresh();
    $this->get("http://a.test/admin/catalog/{$ulid}/preview")->assertRedirect();
    expect($this->get("http://a.test/admin/catalog/{$ulid}/preview")->headers->get('Location'))->toContain('/admin/login');
});

it('answers 404 for another site’s product, as if it did not exist', function (): void {
    $other = previewProduct(site: 2, name: 'Khuôn dập của site khác', slug: 'khuon-dap');
    $manager = previewMember(['catalog.view', 'catalog.manage']);

    $this->actingAs($manager)->get("http://a.test/admin/catalog/{$other}/preview")->assertNotFound();
});

it('answers 404 for a product in the Trash, a site whose plan has no catalog, and a language the site does not have', function (): void {
    $ulid = previewProduct();
    $manager = previewMember(['catalog.view', 'catalog.manage']);
    $url = "http://a.test/admin/catalog/{$ulid}/preview";

    $this->actingAs($manager)->get($url.'?locale=en')->assertNotFound();
    $this->actingAs($manager)->get($url.'?locale[]=vi')->assertNotFound();

    app(Tenancy::class)->enter(1, fn () => SiteModule::query()->update(['enabled' => false]));
    previewFresh();
    $this->actingAs($manager)->get($url)->assertNotFound();

    app(Tenancy::class)->enter(1, fn () => SiteModule::query()->update(['enabled' => true]));
    previewFresh();
    $this->actingAs($manager)->get($url)->assertOk();

    app(Tenancy::class)->enter(1, fn () => Product::query()->firstOrFail()->delete());
    previewFresh();
    $this->actingAs($manager)->get($url)->assertNotFound();
});

it('shows a translation in a language the site has enabled but not opened to visitors yet', function (): void {
    $ulid = previewProduct();
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => false]);
        Product::query()->firstOrFail()->translations()->create(['locale' => 'en', 'name' => 'Inspection jig VF7', 'slug' => 'inspection-jig-vf7']);
    });
    $manager = previewMember(['catalog.view', 'catalog.manage']);

    $html = (string) $this->actingAs($manager)->get("http://a.test/admin/catalog/{$ulid}/preview?locale=en")->assertOk()->getContent();

    expect($html)->toContain('<html lang="en">')
        ->toContain('<h1 class="product__title">Inspection jig VF7</h1>')
        ->toContain('Preview — visitors cannot see this page yet.');
});

it('keeps the draft’s public address a 404: the preview opens no door on the website', function (): void {
    $ulid = previewProduct();
    $path = app(Tenancy::class)->enter(1, fn (): string => app(PathBuilder::class)->forProduct(Product::query()->firstOrFail()->primaryCategory(), 'do-ga-kiem-dinh', 'vi'));

    expect($ulid)->not->toBe('');
    $this->get('http://a.test'.$path)->assertNotFound();
});

it('draws the lead form switched off in the preview and working on the public page', function (): void {
    $ulid = previewProduct(status: 'published');
    previewFormAfterProducts();
    $manager = previewMember(['catalog.view', 'catalog.manage']);
    $path = app(Tenancy::class)->enter(1, fn (): string => app(PathBuilder::class)->forProduct(Product::query()->firstOrFail()->primaryCategory(), 'do-ga-kiem-dinh', 'vi'));

    $public = (string) $this->get('http://a.test'.$path)->assertOk()->getContent();
    previewFresh();
    $preview = (string) $this->actingAs($manager)->get("http://a.test/admin/catalog/{$ulid}/preview")->assertOk()->getContent();

    expect($public)->toContain('<form class="lead-form__form" method="POST" action="/forms/contact" novalidate>')
        ->and($preview)->toContain('<form class="lead-form__form" novalidate>')
        ->toContain('Gửi yêu cầu báo giá')
        ->toContain('<button type="submit" class="lead-form__submit" disabled >')
        ->not->toContain('action=');
});

it('closes the public product page with the site’s analytics beacon, and never the preview', function (): void {
    $ulid = previewProduct(status: 'published');
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'web_analytics', 'value' => ['cloudflare_token' => str_repeat('ab', 16)]]));
    $manager = previewMember(['catalog.view', 'catalog.manage']);
    $path = app(Tenancy::class)->enter(1, fn (): string => app(PathBuilder::class)->forProduct(Product::query()->firstOrFail()->primaryCategory(), 'do-ga-kiem-dinh', 'vi'));

    $public = (string) $this->get('http://a.test'.$path)->assertOk()->getContent();
    previewFresh();
    $preview = (string) $this->actingAs($manager)->get("http://a.test/admin/catalog/{$ulid}/preview")->assertOk()->getContent();

    expect(substr_count($public, 'data-sf-beacon=\'{"token": "'.str_repeat('ab', 16).'"}\''))->toBe(1)
        ->and($preview)->toContain('<h1 class="product__title">Đồ gá kiểm định</h1>')
        ->not->toContain('data-sf-beacon')->not->toContain('beacon.min.js');
});

it('previews a product in the public page’s queries plus the admin’s sign-in and permission checks, exactly', function (): void {
    $ulid = previewProduct(status: 'published');
    $manager = previewMember(['catalog.view', 'catalog.manage']);
    $path = app(Tenancy::class)->enter(1, fn (): string => app(PathBuilder::class)->forProduct(Product::query()->firstOrFail()->primaryCategory(), 'do-ga-kiem-dinh', 'vi'));

    // Both measured with the host already known (the shared cache holds it after the first visit).
    $this->get('http://a.test'.$path)->assertOk();
    previewFresh();
    $public = countQueries(fn () => $this->get('http://a.test'.$path)->assertOk());
    previewFresh();
    $this->actingAs($manager);
    $preview = countQueries(fn () => $this->get("http://a.test/admin/catalog/{$ulid}/preview")->assertOk());

    // The preview finds the product by its ulid instead of resolving a route (−1) and adds the admin's
    // own checks: the site membership (2), the permissions behind catalog.manage (4, read once and
    // then cached), the plan's modules (2) and the permalink setting its address is built from (1).
    expect($preview - $public)->toBe(8)
        ->and($preview)->toBeLessThanOrEqual(22);
});

it('offers the preview on a draft row of the product list, and the website link on a published one', function (): void {
    $draft = previewProduct();
    $live = previewProduct(status: 'published', name: 'Khuôn dập', slug: 'khuon-dap');
    $manager = previewMember(['catalog.view', 'catalog.manage']);

    $html = (string) $this->actingAs($manager)->get('http://a.test/admin/catalog')->assertOk()->getContent();

    expect($html)->toContain('href="http://a.test/admin/catalog/'.$draft.'/preview"')
        ->toContain('aria-label="Xem trước: Đồ gá kiểm định"')
        ->toContain('>Xem trước</span>')
        ->not->toContain('/admin/catalog/'.$live.'/preview');
});

it('previews a draft that has no page in the default language through the first language it has', function (): void {
    $ulid = previewProduct();
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => false]);
        $product = Product::query()->firstOrFail();
        $product->translations()->where('locale', 'vi')->delete();
        $product->translations()->create(['locale' => 'en', 'name' => 'Inspection jig VF7', 'slug' => 'inspection-jig-vf7']);
    });
    $manager = previewMember(['catalog.view', 'catalog.manage']);

    $html = (string) $this->actingAs($manager)->get('http://a.test/admin/catalog')->assertOk()->getContent();

    expect($html)->toContain('href="http://a.test/admin/catalog/'.$ulid.'/preview?locale=en"');
});

it('offers no preview on a published row that has no page in the default language', function (): void {
    $ulid = previewProduct(status: 'published');
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => false]);
        $product = Product::query()->firstOrFail();
        $product->translations()->where('locale', 'vi')->delete();
        $product->translations()->create(['locale' => 'en', 'name' => 'Inspection jig VF7', 'slug' => 'inspection-jig-vf7']);
    });
    $manager = previewMember(['catalog.view', 'catalog.manage']);

    $html = (string) $this->actingAs($manager)->get('http://a.test/admin/catalog')->assertOk()->getContent();

    expect($html)->not->toContain('/admin/catalog/'.$ulid.'/preview');
});

it('previews a draft through the first enabled language it has, skipping a disabled one', function (): void {
    $ulid = previewProduct();
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => false, 'is_public' => false]);
        SiteLocale::query()->create(['locale' => 'ja', 'is_default' => false, 'enabled' => true, 'is_public' => false]);
        $product = Product::query()->firstOrFail();
        $product->translations()->where('locale', 'vi')->delete();
        $product->translations()->create(['locale' => 'en', 'name' => 'Inspection jig VF7', 'slug' => 'inspection-jig-vf7']);
        $product->translations()->create(['locale' => 'ja', 'name' => 'Jig', 'slug' => 'jig-ja']);
    });
    $manager = previewMember(['catalog.view', 'catalog.manage']);

    $html = (string) $this->actingAs($manager)->get('http://a.test/admin/catalog')->assertOk()->getContent();
    expect($html)->toContain('/admin/catalog/'.$ulid.'/preview?locale=ja')->not->toContain('locale=en');
});

it('offers no preview on a draft whose languages are all disabled', function (): void {
    $ulid = previewProduct();
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => false, 'is_public' => false]);
        $product = Product::query()->firstOrFail();
        $product->translations()->where('locale', 'vi')->delete();
        $product->translations()->create(['locale' => 'en', 'name' => 'Inspection jig VF7', 'slug' => 'inspection-jig-vf7']);
    });
    $manager = previewMember(['catalog.view', 'catalog.manage']);

    $html = (string) $this->actingAs($manager)->get('http://a.test/admin/catalog')->assertOk()->getContent();

    expect($html)->not->toContain('/admin/catalog/'.$ulid.'/preview');
});

it('ends the breadcrumb of a preview at the category, with no link to the unpublished address', function (): void {
    $ulid = previewProduct();
    $manager = previewMember(['catalog.view', 'catalog.manage']);

    $html = (string) $this->actingAs($manager)->get("http://a.test/admin/catalog/{$ulid}/preview")->assertOk()->getContent();

    expect($html)->not->toContain('<span class="sf-crumbs__current" aria-current="page">Đồ gá kiểm định</span>')
        ->not->toContain('href="/do-ga/do-ga-kiem-dinh"');
});
