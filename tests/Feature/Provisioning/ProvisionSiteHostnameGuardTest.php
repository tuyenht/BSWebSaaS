<?php

use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\HostnameNotPlatformSubdomainException;
use App\Modules\Provisioning\Exceptions\HostnamePendingVerificationException;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/*
 * §2 domain ownership: a new site is born on a platform subdomain ({slug}.{base}), verified from
 * birth because the platform owns the name. Any other name must be proven (DNS pointing or TXT,
 * design.md §28) through `site:domain … attach` — so provisioning refuses it, or an operator could
 * take a customer's domain (even one another site is waiting to verify) without any proof.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
    config([
        'tenancy.base_domains' => ['platform.test'],
        'tenancy.primary_base' => 'platform.test',
        'tenancy.platform_host' => 'console.platform.test',
        'tenancy.custom_domains.target' => 'target.platform.test',
        'media.cloudflare.base' => 'https://files.platform.test/',
    ]);
});

function provisionHost(string $tenantName, string $host, string $ownerEmail = 'owner@example.test'): Site
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $tenantName,
        siteName: $tenantName,
        hostname: $host,
        ownerEmail: $ownerEmail,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));
}

/** @return array{sites: int, tenants: int, domains: int, users: int, tenant_users: int, model_has_roles: int} */
function hostFootprint(): array
{
    $admin = DB::connection('pgsql_admin');

    return [
        'sites' => $admin->table('sites')->count(),
        'tenants' => $admin->table('tenants')->count(),
        'domains' => $admin->table('domains')->count(),
        'users' => $admin->table('users')->count(),
        'tenant_users' => $admin->table('tenant_users')->count(),
        'model_has_roles' => $admin->table('model_has_roles')->count(),
    ];
}

it('provisions a site on a platform subdomain, verified from birth', function (): void {
    $site = provisionHost('Alpha', 'alpha.platform.test');

    $domain = Domain::query()->where('site_id', $site->id)->sole();
    expect($domain->hostname)->toBe('alpha.platform.test')
        ->and($domain->kind)->toBe('subdomain')
        ->and($domain->verified_at)->not->toBeNull();
});

it('refuses a customer domain another site is waiting to verify, leaving that wait untouched', function (): void {
    $waiting = provisionHost('Beta', 'beta.platform.test', 'owner@beta.test');
    Domain::on('pgsql_admin')->create([
        'site_id' => $waiting->id, 'hostname' => 'customer-b.com', 'kind' => 'custom',
        'is_primary' => false, 'is_redirect' => false,
    ]);
    $before = hostFootprint();

    expect(fn () => provisionHost('Alpha', 'customer-b.com', 'intruder@example.test'))
        ->toThrow(HostnameNotPlatformSubdomainException::class);

    $rows = Domain::query()->where('hostname', 'customer-b.com')->get();
    expect(hostFootprint())->toBe($before)
        ->and(User::query()->where('email', 'intruder@example.test')->exists())->toBeFalse()
        ->and($rows)->toHaveCount(1)
        ->and($rows->sole()->site_id)->toBe($waiting->id)
        ->and($rows->sole()->verified_at)->toBeNull();
});

it('refuses a name that is not one label under a platform base, writing nothing', function (string $host): void {
    $before = hostFootprint();

    expect(fn () => provisionHost('Alpha', $host))->toThrow(HostnameNotPlatformSubdomainException::class);

    expect(hostFootprint())->toBe($before)
        ->and(Tenant::query()->where('name', 'Alpha')->exists())->toBeFalse();
})->with([
    'a customer domain' => 'customer-b.com',
    'the bare base' => 'platform.test',
    'two labels under the base' => 'a.b.platform.test',
    'the www label' => 'www.platform.test',
    'the console host' => 'console.platform.test',
    'the custom-domain target' => 'target.platform.test',
    'the media host' => 'files.platform.test',
    'a look-alike base' => 'alpha.evilplatform.test',
    'not a hostname' => 'not a host',
]);

it('refuses every name while no platform base is configured', function (): void {
    config(['tenancy.base_domains' => [], 'tenancy.primary_base' => '']);

    expect(fn () => provisionHost('Alpha', 'alpha.platform.test'))
        ->toThrow(HostnameNotPlatformSubdomainException::class);

    expect(hostFootprint()['sites'])->toBe(0);
});

it('never treats a single-label base as a platform base', function (): void {
    config(['tenancy.base_domains' => ['test', 'net'], 'tenancy.primary_base' => 'test']);

    foreach (['alpha.test', 'customer.net'] as $host) {
        expect(fn () => provisionHost('Alpha', $host))->toThrow(HostnameNotPlatformSubdomainException::class);
    }

    expect(hostFootprint()['sites'])->toBe(0);
});

it('accepts a subdomain of any configured base', function (): void {
    config(['tenancy.base_domains' => ['platform.test', 'second.test']]);

    $site = provisionHost('Alpha', 'alpha.second.test');

    expect(Domain::query()->where('site_id', $site->id)->sole()->hostname)->toBe('alpha.second.test');
});

it('stores the typed hostname in its plain form and re-runs on any spelling of it', function (): void {
    $site = provisionHost('Alpha', 'https://Alpha.Platform.Test./');

    expect(Domain::query()->where('site_id', $site->id)->sole()->hostname)->toBe('alpha.platform.test');

    $again = provisionHost('Alpha', 'ALPHA.platform.test');

    expect($again->id)->toBe($site->id)
        ->and(Site::query()->count())->toBe(1);
});

it('still re-runs a site on a verified customer domain it already proved', function (): void {
    $site = provisionHost('Alpha', 'alpha.platform.test');
    Domain::on('pgsql_admin')->create([
        'site_id' => $site->id, 'hostname' => 'alpha.vn', 'kind' => 'custom',
        'is_primary' => false, 'is_redirect' => false, 'verified_at' => now(),
    ]);

    expect(provisionHost('Alpha', 'alpha.vn')->id)->toBe($site->id)
        ->and(Site::query()->count())->toBe(1);
});

it('refuses through site:provision with a plain-language message and fails', function (): void {
    $before = hostFootprint();

    // Refused before the password prompt (no question is expected), whatever customer it names.
    $this->artisan('site:provision', [
        '--name' => 'Alpha',
        '--host' => 'Customer-B.com.',
        '--new-tenant' => 'Alpha',
        '--owner-email' => 'intruder@example.test',
    ])
        ->expectsOutput(__('provisioning.hostname_not_platform_subdomain', [
            'host' => 'customer-b.com',
            'base' => 'platform.test',
        ]))
        ->assertFailed();

    expect(hostFootprint())->toBe($before);
});

/** A name Beta's website attached and is still proving, before its base became a platform base. */
function pendingUnderNewBase(): void
{
    $waiting = provisionHost('Beta', 'beta.platform.test', 'owner@beta.test');
    Domain::on('pgsql_admin')->create([
        'site_id' => $waiting->id, 'hostname' => 'shop.newbase.test', 'kind' => 'custom',
        'is_primary' => false, 'is_redirect' => false,
    ]);
    config(['tenancy.base_domains' => ['platform.test', 'newbase.test']]);
}

it('refuses a platform subdomain another website is still proving, writing nothing', function (): void {
    pendingUnderNewBase();
    $before = hostFootprint();

    expect(fn () => provisionHost('Alpha', 'Shop.NewBase.test', 'intruder@example.test'))
        ->toThrow(fn (HostnamePendingVerificationException $e) => expect($e->hostname)->toBe('shop.newbase.test'));

    $row = Domain::query()->where('hostname', 'shop.newbase.test')->sole();
    expect(hostFootprint())->toBe($before)
        ->and(Tenant::query()->where('name', 'Alpha')->exists())->toBeFalse()
        ->and($row->verified_at)->toBeNull()
        ->and($row->site_id)->not->toBeNull();
});

it('explains a name another website is still proving through site:provision', function (): void {
    pendingUnderNewBase();
    $before = hostFootprint();

    $this->artisan('site:provision', [
        '--name' => 'Alpha',
        '--host' => 'shop.newbase.test',
        '--new-tenant' => 'Alpha',
        '--owner-email' => 'intruder@example.test',
    ])
        ->expectsQuestion('Owner password (leave blank to auto-generate)', '')
        ->expectsOutput(__('provisioning.hostname_pending_verification', ['host' => 'shop.newbase.test']))
        ->assertFailed();

    expect(hostFootprint())->toBe($before);
});

it('explains both refusals in each interface language', function (string $locale): void {
    // Read the file itself: __() falls back to English, so a missing key would still translate.
    $lines = json_decode(Cast::string(file_get_contents(lang_path($locale.'.json'))), true);

    expect(is_array($lines) ? array_keys($lines) : [])
        ->toContain('provisioning.hostname_not_platform_subdomain', 'provisioning.hostname_pending_verification');
})->with(['en', 'vi', 'ja', 'zh']);
