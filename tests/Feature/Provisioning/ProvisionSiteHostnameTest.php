<?php

use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\HostnameAwaitingAnotherSiteException;
use App\Modules\Provisioning\Exceptions\HostnameInvalidException;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/*
 * The hostname ProvisionSite is handed, by site:provision or any other caller of the DTO:
 *  - stored in the spelling SiteResolver matches, or refused, so no website exists that no request
 *    reaches (it would still count toward the customer's `sites.max`);
 *  - never one another website is still waiting to verify: only a verified name is unique, so
 *    inserting it verified here would leave that website unable to ever verify it (design.md §28).
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
});

function provisionHost(string $tenantName, string $host, string $ownerEmail, ?int $tenantId = null): Site
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

/** A customer's own domain attached to $site and still waiting for its DNS check. */
function attachPendingDomain(Site $site, string $host): void
{
    DB::connection('pgsql_admin')->table('domains')->insert([
        'site_id' => $site->id, 'hostname' => $host, 'kind' => 'custom', 'is_primary' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array{sites: int, tenants: int, users: int, tenant_users: int, domains: int} */
function hostFootprint(): array
{
    $admin = DB::connection('pgsql_admin');

    return [
        'sites' => $admin->table('sites')->count(),
        'tenants' => $admin->table('tenants')->count(),
        'users' => $admin->table('users')->count(),
        'tenant_users' => $admin->table('tenant_users')->count(),
        'domains' => $admin->table('domains')->count(),
    ];
}

it('refuses a hostname another customer\'s website is waiting to verify, writing nothing', function (): void {
    $beta = provisionHost('Beta', 'beta.test', 'owner@beta.test');
    attachPendingDomain($beta, 'shop.example.vn');
    $before = hostFootprint();

    expect(fn () => provisionHost('Alpha', 'shop.example.vn', 'owner@alpha.test'))
        ->toThrow(HostnameAwaitingAnotherSiteException::class);

    $pending = Domain::on('pgsql_admin')->where('hostname', 'shop.example.vn')->sole();

    expect(hostFootprint())->toBe($before)
        ->and($pending->site_id)->toBe($beta->id)
        ->and($pending->verified_at)->toBeNull();
});

it('refuses a hostname another website of the same customer is waiting to verify', function (): void {
    $first = provisionHost('Beta', 'beta.test', 'owner@beta.test');
    attachPendingDomain($first, 'shop.example.vn');
    $before = hostFootprint();

    expect(fn () => provisionHost('Beta', 'shop.example.vn', 'owner@beta.test', tenantId: $first->tenant_id))
        ->toThrow(HostnameAwaitingAnotherSiteException::class);

    expect(hostFootprint())->toBe($before);
});

it('refuses a waiting hostname in any spelling, normalizing it first', function (): void {
    attachPendingDomain(provisionHost('Beta', 'beta.test', 'owner@beta.test'), 'shop.example.vn');

    expect(fn () => provisionHost('Alpha', 'Shop.Example.VN.', 'owner@alpha.test'))
        ->toThrow(HostnameAwaitingAnotherSiteException::class);
});

it('refuses a waiting hostname through site:provision in plain words and fails', function (): void {
    attachPendingDomain(provisionHost('Beta', 'beta.test', 'owner@beta.test'), 'shop.example.vn');
    $before = hostFootprint();

    $this->artisan('site:provision', [
        '--name' => 'Alpha',
        '--host' => 'shop.example.vn',
        '--tenant' => 'Alpha',
        '--owner-email' => 'owner@alpha.test',
    ])
        ->expectsQuestion('Owner password (leave blank to auto-generate)', '')
        ->expectsOutput(__('provisioning.hostname_awaiting_another_site', ['host' => 'shop.example.vn']))
        ->assertFailed();

    expect(hostFootprint())->toBe($before);
});

it('stores the hostname in the spelling SiteResolver matches, whoever builds the input', function (): void {
    $site = provisionHost('Alpha', '  A1.Test. ', 'owner@alpha.test');

    expect(Domain::on('pgsql_admin')->where('site_id', $site->id)->pluck('hostname')->all())->toBe(['a1.test']);
});

it('re-runs the same website for another spelling of its hostname, keeping every other input', function (): void {
    $site = provisionHost('Alpha', 'a1.test', 'owner@alpha.test');

    // Only the id names the customer here: were it lost while re-spelling the host, the unknown name
    // would be refused as another customer.
    $again = provisionHost('ignored', 'A1.TEST.', 'owner@alpha.test', tenantId: $site->tenant_id);

    expect($again->id)->toBe($site->id)
        ->and(Site::query()->count())->toBe(1)
        ->and(Domain::on('pgsql_admin')->count())->toBe(1);
});

it('refuses a hostname no request could ever reach, writing nothing', function (string $host): void {
    $before = hostFootprint();

    expect(fn () => provisionHost('Alpha', $host, 'owner@alpha.test'))
        ->toThrow(HostnameInvalidException::class);

    expect(hostFootprint())->toBe($before)
        ->and(User::query()->where('email', 'owner@alpha.test')->exists())->toBeFalse();
})->with([
    'empty' => [''],
    'only a dot' => ['.'],
    'a single label' => ['localhost'],
    'an underscore' => ['bad_host.test'],
    'an IP address' => ['10.0.0.1'],
    'a space inside' => ['a b.test'],
]);

it('refuses an unusable hostname through site:provision in plain words and fails', function (): void {
    $this->artisan('site:provision', [
        '--name' => 'Alpha',
        '--host' => 'bad_host.test',
        '--tenant' => 'Alpha',
        '--owner-email' => 'owner@alpha.test',
    ])
        ->expectsQuestion('Owner password (leave blank to auto-generate)', '')
        ->expectsOutput(__('provisioning.host_invalid', ['host' => 'bad_host.test']))
        ->assertFailed();

    expect(hostFootprint()['sites'])->toBe(0);
});

it('explains both refusals in every interface language', function (string $key): void {
    foreach (['en', 'vi', 'ja', 'zh'] as $locale) {
        $text = __($key, ['host' => 'shop.example.vn'], $locale);

        expect($text)->not->toBe($key)
            ->and($text)->toContain('shop.example.vn');
    }
})->with([
    'provisioning.hostname_awaiting_another_site',
    'provisioning.host_invalid',
]);
