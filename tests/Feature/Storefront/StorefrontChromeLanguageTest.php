<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/*
 * The storefront's own words — navigation labels, footer, pagination, the home crumb — follow the
 * language of the page, and the home links stay in it. The footer names no tenant, year or
 * colour of its own, and carries no inline script (the storefront CSP would block it).
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

/** A vi site with a public en, one page in both languages, and a brand with a logo and licence. */
function chromeLanguageSite(): void
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Chrome', siteName: 'Chrome', hostname: 'chrome.test',
        ownerEmail: 'owner@chrome.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));
    $site = Site::query()->firstWhere('name', 'Chrome');

    app(Tenancy::class)->enter($site->id, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'general', 'key' => 'brand'],
            ['value' => ['name' => 'Xưởng Mẫu', 'logo' => 'https://files.baoson.net/logo.png', 'license' => '0100000001', 'phone' => '0901 234 567']],
        );

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $blocks = fn (string $heading): array => ['blocks' => [['type' => 'hero', 'props' => ['heading' => $heading]]]];
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => $blocks('Giới thiệu')]);
        $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'published', 'body' => $blocks('About')]);

        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $entry->id]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/about', 'entry_id' => $entry->id]);
    });
}

it('speaks the page language in the footer and links home in it', function (): void {
    chromeLanguageSite();
    $year = date('Y');

    expect($this->get('http://chrome.test/en/about')->assertOk()->getContent())
        ->toContain('<p class="site-footer__heading">Navigation</p>')
        ->toContain('Business registration no.: 0100000001')
        ->toContain('Hotline: <a href="tel:0901234567">')
        ->toContain("© {$year} Xưởng Mẫu. All rights reserved.")
        ->toContain('<a class="site-header__brand" href="/en"')
        ->not->toContain('Bảo lưu mọi quyền')
        ->not->toContain('Điều hướng');

    expect($this->get('http://chrome.test/gioi-thieu')->assertOk()->getContent())
        ->toContain('<p class="site-footer__heading">Điều hướng</p>')
        ->toContain('GPĐKKD: 0100000001')
        ->toContain("© {$year} Xưởng Mẫu. Bảo lưu mọi quyền.")
        ->toContain('<a class="site-header__brand" href="/"');
});

it('keeps the footer free of inline script, fixed years and hardcoded colours', function (): void {
    chromeLanguageSite();

    expect($this->get('http://chrome.test/gioi-thieu')->assertOk()->getContent())
        ->not->toContain('onmouseover')
        ->not->toContain('onmouseout')
        ->not->toContain('2024–')
        ->not->toContain('#f59e0b');
});
