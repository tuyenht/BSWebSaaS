<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Actions\ChangeDefaultSiteLocale;
use App\Modules\Tenancy\Context\SiteLocale as SiteLocaleContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Exceptions\SiteLocaleChangeRejectedException;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * A site's default language is mutable (design.md §10/ADR-11). Routes keep unprefixed paths per
 * language and PathBuilder lays the prefix over them from the current default, so a change of
 * default moves every address without rewriting a row. The storefront keeps the old addresses
 * working with 301s: `/{new default}/…` → the clean path (the prefix guard), and a clean path the
 * new default does not hold but the former default does (a live page or a stored redirect) →
 * `/{former}/…`. Stored redirects keep the unprefixed path of their language, so their target gets
 * that language's prefix too.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, redirects, routes, product_translations, product_images, products,
         entry_translations, entries,
         site_profile_translations, site_profiles, site_settings, site_locales, site_features,
         site_modules, content_sections, domains, sites, tenants, tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
});

const DLC_HOST = 'http://doimacdinh.platform.test';

/** A provisioned site with vi (default) and en public, «Giới thiệu» at /gioi-thieu and «About» at /about. */
function dlcSite(): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Đổi Mặc Định', siteName: 'Đổi Mặc Định', hostname: 'doimacdinh.platform.test',
        ownerEmail: 'owner@doimacdinh.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $site = Site::query()->firstWhere('name', 'Đổi Mặc Định')->id;

    app(Tenancy::class)->enter($site, function (): void {
        SiteFeature::query()->create(['feature_key' => 'locales.max', 'value' => 3]);
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $entry->id]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/about', 'entry_id' => $entry->id]);
    });

    return $site;
}

function dlcMakeDefault(int $site, string $locale): void
{
    app(Tenancy::class)->enter($site, fn () => app(ChangeDefaultSiteLocale::class)->handle($locale));
    app(SiteLocaleContext::class)->forget();
}

/** Path component of a response's Location header ('' → '/'). */
function dlcLocation(TestResponse $response): string
{
    return parse_url((string) $response->headers->get('Location'), PHP_URL_PATH) ?: '/';
}

it('moves every address with the default: the new default is clean, the old one is prefixed', function (): void {
    $site = dlcSite();
    $this->get(DLC_HOST.'/gioi-thieu')->assertOk();
    $this->get(DLC_HOST.'/en/about')->assertOk();

    dlcMakeDefault($site, 'en');

    expect(dlcLocation($this->get(DLC_HOST.'/en/about')->assertStatus(301)))->toBe('/about')
        ->and(dlcLocation($this->get(DLC_HOST.'/gioi-thieu')->assertStatus(301)))->toBe('/vi/gioi-thieu');
    $this->get(DLC_HOST.'/about')->assertOk()->assertSee('<html lang="en">', false);
    $this->get(DLC_HOST.'/vi/gioi-thieu')->assertOk()->assertSee('<html lang="vi">', false);
    $this->get(DLC_HOST.'/khong-co-trang-nay')->assertNotFound();
});

it('keeps the former default\'s stored redirects working under its prefix', function (): void {
    $site = dlcSite();
    app(Tenancy::class)->enter($site, fn () => Redirect::query()->create(['locale' => 'vi', 'from_path' => '/ve-chung-toi', 'to_path' => '/gioi-thieu', 'status_code' => 301]));

    dlcMakeDefault($site, 'en');

    $first = $this->get(DLC_HOST.'/ve-chung-toi')->assertStatus(301);
    expect(dlcLocation($first))->toBe('/vi/ve-chung-toi')
        ->and(dlcLocation($this->get(DLC_HOST.'/vi/ve-chung-toi')->assertStatus(301)))->toBe('/vi/gioi-thieu');
});

it('gives a non-default language\'s stored redirect its prefix', function (): void {
    $site = dlcSite();
    app(Tenancy::class)->enter($site, fn () => Redirect::query()->create(['locale' => 'en', 'from_path' => '/about-us', 'to_path' => '/about', 'status_code' => 302]));

    expect(dlcLocation($this->get(DLC_HOST.'/en/about-us')->assertStatus(302)))->toBe('/en/about');
});

it('sends nothing to a language visitors cannot see, nor to a page that is not published there', function (): void {
    $site = dlcSite();
    app(Tenancy::class)->enter($site, function (): void {
        SiteLocale::query()->create(['locale' => 'ja', 'is_default' => false, 'enabled' => true, 'is_public' => false]);
        $draft = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $draft->translations()->create(['locale' => 'vi', 'title' => 'Bản nháp', 'slug' => 'ban-nhap', 'status' => 'draft', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/ban-nhap', 'entry_id' => $draft->id]);
        $hidden = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $hidden->translations()->create(['locale' => 'ja', 'title' => '会社', 'slug' => 'kaisha', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'ja', 'path' => '/kaisha', 'entry_id' => $hidden->id]);
    });

    dlcMakeDefault($site, 'en');

    $this->get(DLC_HOST.'/ban-nhap')->assertNotFound();
    $this->get(DLC_HOST.'/kaisha')->assertNotFound();
});

it('never sends a clean path into a language that was not the default', function (): void {
    dlcSite();

    // English is public but was never the default: /about is not a Vietnamese address.
    $this->get(DLC_HOST.'/about')->assertNotFound();
});

it('remembers every former default, the most recent first, and drops the current one', function (): void {
    $site = dlcSite();
    dlcMakeDefault($site, 'en');
    dlcMakeDefault($site, 'vi');

    // Back to Vietnamese: English stepped down, so its old clean address now lives under /en.
    expect(dlcLocation($this->get(DLC_HOST.'/about')->assertStatus(301)))->toBe('/en/about');
    $this->get(DLC_HOST.'/gioi-thieu')->assertOk();
    expect(app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->where('group_name', 'locales')->where('key', 'former_defaults')->value('value')))
        ->toBe(['codes' => ['en']]);
});

it('keeps the order of former defaults and only the last four', function (): void {
    $site = dlcSite();
    app(Tenancy::class)->enter($site, fn () => SiteFeature::query()->where('feature_key', 'locales.max')->update(['value' => 6]));
    $former = fn (): mixed => app(Tenancy::class)->enter($site, fn () => SiteSetting::query()->where('group_name', 'locales')->where('key', 'former_defaults')->value('value'));

    dlcMakeDefault($site, 'en');
    dlcMakeDefault($site, 'ja');
    dlcMakeDefault($site, 'vi');
    expect($former())->toBe(['codes' => ['ja', 'en']]);

    foreach (['en', 'ja', 'zh', 'ko', 'fr'] as $code) {
        dlcMakeDefault($site, $code);
    }
    expect($former())->toBe(['codes' => ['ko', 'zh', 'ja', 'en']]);
});

it('sends a former default\'s product address on only while the product is published there', function (string $status, int $expected): void {
    $site = dlcSite();
    $path = app(Tenancy::class)->enter($site, function () use ($status): string {
        $product = Product::query()->create(['status' => $status, 'published_at' => $status === 'published' ? now() : null]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá kiểm', 'slug' => 'do-ga-kiem', 'description' => '<p>Đồ gá.</p>']);

        return app(RouteSync::class)->syncProduct($product->id, null, 'vi', 'do-ga-kiem');
    });
    dlcMakeDefault($site, 'en');

    $response = $this->get(DLC_HOST.$path)->assertStatus($expected);
    if ($expected === 301) {
        expect(dlcLocation($response))->toBe('/vi'.$path);
    }
})->with([
    'published' => ['published', 301],
    'draft' => ['draft', 404],
]);

it('refuses a default the plan has no room for and changes nothing', function (): void {
    $site = dlcSite();

    $reason = app(Tenancy::class)->enter($site, function (): ?string {
        SiteFeature::query()->where('feature_key', 'locales.max')->update(['value' => 2]);
        try {
            app(ChangeDefaultSiteLocale::class)->handle('ja');
        } catch (SiteLocaleChangeRejectedException $e) {
            return $e->reason;
        }

        return null;
    });

    expect($reason)->toBe('limit_reached')
        ->and(app(Tenancy::class)->enter($site, fn () => SiteLocale::query()->where('is_default', true)->pluck('locale')->all()))->toBe(['vi']);
});
