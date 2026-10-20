<?php

use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\HostnameOwnedByAnotherTenantException;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/*
 * §2 cross-tenant guard: an existing hostname makes ProvisionSite a re-run of THAT site, so the
 * input must name that site's own customer. Otherwise an operator typo (`--host` of customer B,
 * `--tenant` of customer A) would make the owner email an owner of B's website.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
});

function provisionGuarded(string $tenantName, string $host, string $ownerEmail, ?int $tenantId = null): Site
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $tenantName,
        siteName: $tenantName,
        hostname: $host,
        ownerEmail: $ownerEmail,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
        tenantId: $tenantId,
    ));
}

/** @return array{tenant_users: int, roles: int, tenants: int} */
function guardFootprint(): array
{
    $admin = DB::connection('pgsql_admin');

    return [
        'tenant_users' => $admin->table('tenant_users')->count(),
        'roles' => $admin->table('model_has_roles')->count(),
        'tenants' => $admin->table('tenants')->count(),
    ];
}

it('still re-runs a site for its own customer, by name and by id', function (): void {
    $site = provisionGuarded('Beta', 'beta.test', 'owner@beta.test');

    $byName = provisionGuarded('Beta', 'beta.test', 'owner@beta.test');
    $byId = provisionGuarded('ignored', 'beta.test', 'owner@beta.test', tenantId: $site->tenant_id);

    expect($byName->id)->toBe($site->id)
        ->and($byId->id)->toBe($site->id)
        ->and(Site::query()->count())->toBe(1)
        ->and(Tenant::query()->count())->toBe(1);
});

it('refuses a hostname of another customer named by tenant name, writing nothing', function (): void {
    provisionGuarded('Alpha', 'alpha.test', 'owner@alpha.test');
    provisionGuarded('Beta', 'beta.test', 'owner@beta.test');
    $before = guardFootprint();

    expect(fn () => provisionGuarded('Alpha', 'beta.test', 'intruder@example.test'))
        ->toThrow(HostnameOwnedByAnotherTenantException::class);

    expect(guardFootprint())->toBe($before)
        ->and(User::query()->where('email', 'intruder@example.test')->exists())->toBeFalse();
});

it('refuses a hostname of another customer named by an unknown tenant name, creating no customer', function (): void {
    provisionGuarded('Beta', 'beta.test', 'owner@beta.test');
    $before = guardFootprint();

    expect(fn () => provisionGuarded('Typo Co', 'beta.test', 'owner@beta.test'))
        ->toThrow(HostnameOwnedByAnotherTenantException::class);

    expect(guardFootprint())->toBe($before)
        ->and(Tenant::query()->where('name', 'Typo Co')->exists())->toBeFalse();
});

it('refuses a hostname of another customer named by tenant id, writing nothing', function (): void {
    $alpha = provisionGuarded('Alpha', 'alpha.test', 'owner@alpha.test');
    provisionGuarded('Beta', 'beta.test', 'owner@beta.test');
    $before = guardFootprint();

    // The name matches B: the explicit id wins and names A, so this is still refused.
    expect(fn () => provisionGuarded('Beta', 'beta.test', 'owner@alpha.test', tenantId: $alpha->tenant_id))
        ->toThrow(HostnameOwnedByAnotherTenantException::class);

    expect(guardFootprint())->toBe($before);
});

it('refuses through site:provision with a plain-language message and fails', function (): void {
    provisionGuarded('Alpha', 'alpha.test', 'owner@alpha.test');
    provisionGuarded('Beta', 'beta.test', 'owner@beta.test');
    $before = guardFootprint();

    $this->artisan('site:provision', [
        '--name' => 'Alpha',
        '--host' => 'beta.test',
        '--tenant' => 'Alpha',
        '--owner-email' => 'intruder@example.test',
    ])
        ->expectsQuestion('Owner password (leave blank to auto-generate)', '')
        ->expectsOutput(__('provisioning.hostname_owned_by_another_tenant', ['host' => 'beta.test']))
        ->assertFailed();

    expect(guardFootprint())->toBe($before);
});
