<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use Illuminate\Support\Facades\DB;

/*
 * The shared 404 view renders for every tenant, the operator host and unknown hosts. It must
 * name the site the visitor actually asked for — never a hardcoded tenant brand (white-label).
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

function errorBrandProvision(string $siteName, string $hostname): void
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $siteName,
        siteName: $siteName,
        hostname: $hostname,
        ownerEmail: 'owner@'.$hostname,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));
}

it('names the requested site on its 404 page, not another tenant', function (): void {
    errorBrandProvision('Nội Thất Hoà Phát', 'hoaphat.test');

    $html = $this->get('http://hoaphat.test/khong-ton-tai')->assertNotFound()->getContent();

    expect($html)
        ->toContain('Nội Thất Hoà Phát')
        ->toContain('Không tìm thấy')
        ->toContain('href="/"')
        ->not->toContain('An Vinh');
});

it('falls back to the bare host on an unknown host', function (): void {
    $html = $this->get('http://chua-gan-website.test/bat-ky')->assertNotFound()->getContent();

    expect($html)
        ->toContain('chua-gan-website.test')
        ->not->toContain('An Vinh');
});

it('shows the platform brand on the operator host', function (): void {
    config(['admin.brand.name' => 'Nền Tảng Thử']);
    $host = (string) config('tenancy.platform_host');

    $html = $this->get("http://{$host}/khong-co-trang-nay")->assertNotFound()->getContent();

    expect($html)
        ->toContain('Nền Tảng Thử')
        ->not->toContain('An Vinh');
});
