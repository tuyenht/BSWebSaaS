<?php

use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * C3 — storefront locale-prefix guard (design.md §10/ADR-11). The site's current default
 * locale is unprefixed: a /{default}/… request 301s (permanent) to the clean path. A valid
 * ENABLED non-default prefix selects that locale; an unknown/disabled prefix, or an enabled
 * locale with a route-miss, 404s with NO soft-fallback to the default. The default is read
 * per request (C1 memo), so flipping it flips which prefix is canonical.
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

/** Enable 'en' for the site + give the news category an en translation + en list route. */
function enableEnglishNews(int $site): void
{
    app(Tenancy::class)->enter($site, function (): void {
        SiteLocale::create(['locale' => 'en', 'is_default' => false, 'enabled' => true]);
        $category = Taxonomy::query()->where('vocabulary', 'post_cat')->first();
        $category->translations()->create(['locale' => 'en', 'name' => 'News', 'slug' => 'tin-tuc']);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/tin-tuc', 'taxonomy_id' => $category->id]);
    });
}

/** Path component of a response's Location header ('' → '/'). */
function redirectPath(TestResponse $response): string
{
    return parse_url((string) $response->headers->get('Location'), PHP_URL_PATH) ?: '/';
}

it('301s a default-locale prefix to the clean path', function (): void {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');

    $res = $this->get('http://anvinh.test/vi/tin-tuc')->assertStatus(301);   // permanent

    expect(redirectPath($res))->toBe('/tin-tuc');
});

it('301s the default-locale root prefix to /', function (): void {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');

    $res = $this->get('http://anvinh.test/vi')->assertStatus(301);

    expect(redirectPath($res))->toBe('/');
});

it('serves a valid non-default locale prefix (200, localized)', function (): void {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');
    $site = Site::query()->firstWhere('name', 'An Vinh');
    enableEnglishNews($site->id);

    $html = $this->get('http://anvinh.test/en/tin-tuc')->assertOk()->getContent();

    expect($html)->toContain('<title>News</title>')->toContain('News');
});

it('404s a disabled/unknown locale prefix without soft-fallback', function (): void {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');

    // 'ko' is not enabled → 404; must NOT render the default /tin-tuc page.
    $this->get('http://anvinh.test/ko/tin-tuc')->assertNotFound();
});

it('404s an enabled locale with a route-miss (no soft-fallback)', function (): void {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');
    $site = Site::query()->firstWhere('name', 'An Vinh');
    enableEnglishNews($site->id);

    // 'en' is enabled but has no route for this path → 404 (not the vi page).
    $this->get('http://anvinh.test/en/khong-ton-tai')->assertNotFound();
});

it('serves the default locale unprefixed (200)', function (): void {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');

    $this->get('http://anvinh.test/tin-tuc')->assertOk();
});

it('reads the default dynamically — flipping it flips which prefix is canonical', function (): void {
    provision('An Vinh', 'anvinh.test', 'owner@anvinh.test');
    $site = Site::query()->firstWhere('name', 'An Vinh');
    enableEnglishNews($site->id);

    // Flip the site default vi → en (source of truth).
    $admin = DB::connection('pgsql_admin');
    $admin->table('site_locales')->where('site_id', $site->id)->where('locale', 'vi')->update(['is_default' => false]);
    $admin->table('site_locales')->where('site_id', $site->id)->where('locale', 'en')->update(['is_default' => true]);

    // en is now the unprefixed default → its prefix is a canonical violation → 301.
    $res = $this->get('http://anvinh.test/en/tin-tuc')->assertStatus(301);
    expect(redirectPath($res))->toBe('/tin-tuc');

    // vi is now a valid non-default prefix → resolves the vi category → 200.
    $this->get('http://anvinh.test/vi/tin-tuc')->assertOk();
});
