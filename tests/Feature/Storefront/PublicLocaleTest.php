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
 * A language can be enabled for editors before visitors see it (`site_locales.is_public`).
 * Until it is public, nothing on the storefront may lead to it: its URL prefix 404s, hreflang
 * leaves it out and the sitemap does not list its routes. The sitemap also lists only routes the
 * storefront would actually render — never a draft translation.
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

/** A vi site with an "about" page translated to en; `en` is enabled, public or not as asked. */
function publicLocaleSite(bool $englishPublic, string $englishStatus = 'published'): int
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Lang', siteName: 'Lang', hostname: 'lang.test',
        ownerEmail: 'owner@lang.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $site = Site::query()->firstWhere('name', 'Lang');

    app(Tenancy::class)->enter($site->id, function () use ($englishPublic, $englishStatus): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => $englishPublic]);

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $blocks = fn (string $heading): array => ['blocks' => [['type' => 'hero', 'props' => ['heading' => $heading]]]];
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Về chúng tôi', 'slug' => 've-chung-toi', 'status' => 'published', 'body' => $blocks('Về chúng tôi')]);
        $entry->translations()->create(['locale' => 'en', 'title' => 'About us', 'slug' => 'about-us', 'status' => $englishStatus, 'body' => $blocks('About us')]);

        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/ve-chung-toi', 'entry_id' => $entry->id]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/about-us', 'entry_id' => $entry->id]);
    });

    return $site->id;
}

it('404s the prefix of a language that is enabled but not public yet', function (): void {
    publicLocaleSite(englishPublic: false);

    $this->get('http://lang.test/en/about-us')->assertNotFound();
    $this->get('http://lang.test/ve-chung-toi')->assertOk();
});

it('serves the prefix once the language is public', function (): void {
    publicLocaleSite(englishPublic: true);

    expect($this->get('http://lang.test/en/about-us')->assertOk()->getContent())->toContain('About us');
});

it('leaves a not-yet-public language out of hreflang', function (): void {
    publicLocaleSite(englishPublic: false);

    expect($this->get('http://lang.test/ve-chung-toi')->assertOk()->getContent())
        ->toContain('<link rel="alternate" hreflang="vi" href="http://lang.test/ve-chung-toi">')
        ->not->toContain('hreflang="en"');
});

it('lists a public language in hreflang', function (): void {
    publicLocaleSite(englishPublic: true);

    expect($this->get('http://lang.test/ve-chung-toi')->assertOk()->getContent())
        ->toContain('<link rel="alternate" hreflang="en" href="http://lang.test/en/about-us">');
});

it('keeps a not-yet-public language out of the sitemap', function (): void {
    publicLocaleSite(englishPublic: false);

    expect($this->get('http://lang.test/sitemap.xml')->assertOk()->getContent())
        ->toContain('<loc>http://lang.test/ve-chung-toi</loc>')
        ->not->toContain('/en/about-us');
});

it('lists a public language in the sitemap', function (): void {
    publicLocaleSite(englishPublic: true);

    expect($this->get('http://lang.test/sitemap.xml')->assertOk()->getContent())
        ->toContain('<loc>http://lang.test/en/about-us</loc>');
});

it('never lists a draft translation in the sitemap, even of a published page', function (): void {
    publicLocaleSite(englishPublic: true, englishStatus: 'draft');

    // The storefront 404s the draft translation, so the sitemap must not advertise it.
    $this->get('http://lang.test/en/about-us')->assertNotFound();

    expect($this->get('http://lang.test/sitemap.xml')->assertOk()->getContent())
        ->toContain('<loc>http://lang.test/ve-chung-toi</loc>')
        ->not->toContain('/en/about-us');
});
