<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\SiteDirectory;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/*
 * Every 404 names the site the visitor actually asked for — never a hardcoded tenant brand
 * (white-label). A page of a site that is not found gets the site's own 404 page (its brand, in
 * its context); the plain 404 view serves scanner paths and the operator host, and finds the name
 * from the request host; an unknown host gets the neutral page, which names no one.
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
    errorBrandProvision('Nội Thất Hoà Phát', 'hoaphat.platform.test');
    $site = Site::query()->where('name', 'Nội Thất Hoà Phát')->firstOrFail();
    app(Tenancy::class)->enter($site->id, fn () => SiteProfile::query()->firstOrCreate([])->update(['brand_name' => 'Nội Thất Hoà Phát']));

    $html = $this->get('http://hoaphat.platform.test/khong-ton-tai')->assertNotFound()->getContent();

    expect($html)
        ->toContain('Nội Thất Hoà Phát')
        ->toContain('Không tìm thấy')
        ->toContain('href="/"')
        ->not->toContain('An Vinh');
});

it('names the requested site on the plain 404 a scanner path gets', function (): void {
    errorBrandProvision('Nội Thất Hoà Phát', 'hoaphat.platform.test');

    $html = $this->get('http://hoaphat.platform.test/wp-login.php')->assertNotFound()->getContent();

    expect($html)
        ->toContain('class="error-page"')
        ->toContain('Nội Thất Hoà Phát')
        ->not->toContain('An Vinh');
});

it('names neither a brand nor the host on an unknown host', function (): void {
    $html = $this->get('http://chua-gan-website.test/bat-ky')->assertNotFound()->getContent();

    expect($html)
        ->toContain('Không tìm thấy website')
        ->not->toContain('chua-gan-website')
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

it('reports a fault while finding the site and never claims the host has no website', function (): void {
    errorBrandProvision('Nội Thất Hoà Phát', 'hoaphat.platform.test');
    Exceptions::fake();
    // The lookup fails on a fault (the database unreachable…), not on a host no site owns.
    app()->instance(SiteDirectory::class, new class
    {
        public function nameForHost(string $host): ?string
        {
            throw new RuntimeException('site lookup failed');
        }
    });

    $html = (string) $this->get('http://hoaphat.platform.test/wp-login.php')->assertNotFound()->getContent();

    expect($html)
        ->toContain('Không tìm thấy trang này')
        ->toContain('Trang bạn tìm có thể đã đổi địa chỉ hoặc không còn.')
        ->toContain('<meta name="robots" content="noindex">')
        ->not->toContain('Không tìm thấy website')
        ->not->toContain('chưa gắn với website nào')
        ->not->toContain('site lookup failed')
        ->not->toContain('hoaphat');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'site lookup failed');
});
