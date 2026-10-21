<?php

use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\HostnameNotPlatformSubdomainException;
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

/** @return array{sites: int, tenants: int, domains: int, users: int, tenant_users: int} */
function hostFootprint(): array
{
    $admin = DB::connection('pgsql_admin');

    return [
        'sites' => $admin->table('sites')->count(),
        'tenants' => $admin->table('tenants')->count(),
        'domains' => $admin->table('domains')->count(),
        'users' => $admin->table('users')->count(),
        'tenant_users' => $admin->table('tenant_users')->count(),
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

    $this->artisan('site:provision', [
        '--name' => 'Alpha',
        '--host' => 'customer-b.com',
        '--owner-email' => 'intruder@example.test',
    ])
        ->expectsQuestion('Owner password (leave blank to auto-generate)', '')
        ->expectsOutput(__('provisioning.hostname_not_platform_subdomain', [
            'host' => 'customer-b.com',
            'base' => 'platform.test',
        ]))
        ->assertFailed();

    expect(hostFootprint())->toBe($before);
});
