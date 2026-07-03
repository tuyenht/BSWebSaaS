<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Facades\DB;

/*
 * C4 — hreflang + dynamic <html lang> (design.md §10/§18). One <link rel="alternate"
 * hreflang> per PUBLISHED locale (URL via publicUrl → vi unprefixed, en /en/..), ABSOLUTE,
 * RECIPROCAL, + x-default → the site default. A locale the page is NOT published in gets NO
 * hreflang (never point at an empty page). <html lang> is the locale being viewed. Kept thin.
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

/** Provision An Vinh (self-contained — no cross-file helper). */
function provisionAnVinhSite(): Site
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh',
        siteName: 'An Vinh',
        hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));

    return Site::query()->firstWhere('name', 'An Vinh');
}

/** Provision An Vinh + enable 'en'; return the site. */
function provisionBilingual(): Site
{
    $site = provisionAnVinhSite();
    app(Tenancy::class)->enter($site->id, fn () => SiteLocale::create(['locale' => 'en', 'is_default' => false, 'enabled' => true]));

    return $site;
}

/** A page published in vi (/gioi-thieu) + en (/about). */
function seedAboutPage(int $site): void
{
    app(Tenancy::class)->enter($site, function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $entry->id]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/about', 'entry_id' => $entry->id]);
    });
}

it('sets <html lang> to the locale being viewed', function (): void {
    $site = provisionBilingual();
    seedAboutPage($site->id);

    expect($this->get('http://anvinh.test/gioi-thieu')->getContent())->toContain('<html lang="vi">');
    expect($this->get('http://anvinh.test/en/about')->getContent())->toContain('<html lang="en">');
});

it('emits one absolute hreflang per published locale + x-default (vi view)', function (): void {
    $site = provisionBilingual();
    seedAboutPage($site->id);

    $html = $this->get('http://anvinh.test/gioi-thieu')->assertOk()->getContent();

    expect($html)
        ->toContain('<link rel="alternate" hreflang="vi" href="http://anvinh.test/gioi-thieu">')
        ->toContain('<link rel="alternate" hreflang="en" href="http://anvinh.test/en/about">')
        ->toContain('<link rel="alternate" hreflang="x-default" href="http://anvinh.test/gioi-thieu">');
});

it('emits reciprocal hreflang from the en view (back to vi + self en)', function (): void {
    $site = provisionBilingual();
    seedAboutPage($site->id);

    $html = $this->get('http://anvinh.test/en/about')->assertOk()->getContent();

    expect($html)
        ->toContain('hreflang="vi" href="http://anvinh.test/gioi-thieu"')
        ->toContain('hreflang="en" href="http://anvinh.test/en/about"')
        ->toContain('hreflang="x-default" href="http://anvinh.test/gioi-thieu"')
        ->toContain('<html lang="en">');
});

it('omits hreflang for a locale the page is not published in', function (): void {
    $site = provisionBilingual();   // 'en' enabled site-wide
    app(Tenancy::class)->enter($site->id, function (): void {
        // A page published in vi ONLY (no en translation/route). Uses /chinh-sach because
        // /lien-he is now an auto-provisioned contact route (would collide on the unique path).
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Chính sách', 'slug' => 'chinh-sach', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/chinh-sach', 'entry_id' => $entry->id]);
    });

    $html = $this->get('http://anvinh.test/chinh-sach')->assertOk()->getContent();

    expect($html)
        ->toContain('hreflang="vi" href="http://anvinh.test/chinh-sach"')
        ->toContain('hreflang="x-default"')
        ->not->toContain('hreflang="en"');   // page has no en translation → no en hreflang
});

it('self-references hreflang on a single-locale page', function (): void {
    provisionAnVinhSite();   // pure vi site (hot-path shape)

    $html = $this->get('http://anvinh.test/')->assertOk()->getContent();

    expect($html)->toContain('<html lang="vi">')
        ->toContain('hreflang="vi"')
        ->toContain('hreflang="x-default"');
});
