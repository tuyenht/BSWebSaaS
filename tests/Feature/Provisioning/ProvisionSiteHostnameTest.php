<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\UnusableHostnameException;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/*
 * ProvisionSite stores the hostname SiteResolver matches, whoever calls it (site:provision, the
 * self-signup to come). A spelling such as `A1.Test.` stored as given would be a verified primary
 * domain no request ever resolves to: a dead website that still counts toward `sites.max`.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
});

function provisionHost(string $host): Site
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'A1',
        siteName: 'A1',
        hostname: $host,
        ownerEmail: 'owner@a1.test',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));
}

/** @return array<string, int> */
function hostnameFootprint(): array
{
    $admin = DB::connection('pgsql_admin');

    return collect(['tenants', 'sites', 'domains', 'users', 'tenant_users'])
        ->mapWithKeys(fn (string $table): array => [$table => $admin->table($table)->count()])
        ->all();
}

it('re-runs the existing website for another spelling of its hostname', function (): void {
    $site = provisionHost('a1.test');

    $again = provisionHost('A1.Test.');

    expect($again->id)->toBe($site->id)
        ->and(Site::query()->count())->toBe(1)
        ->and(Tenant::query()->count())->toBe(1)
        ->and(Domain::query()->pluck('hostname')->all())->toBe(['a1.test']);
});

it('stores the hostname in the spelling SiteResolver matches', function (): void {
    $site = provisionHost('  A1.Test. ');

    expect(Domain::query()->where('site_id', $site->id)->pluck('hostname')->all())->toBe(['a1.test']);
});

it('refuses a hostname no website can use, writing nothing', function (string $host): void {
    provisionHost('a1.test');
    $before = hostnameFootprint();

    expect(fn () => app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Someone new',
        siteName: 'Someone new',
        hostname: $host,
        ownerEmail: 'new@example.test',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    )))->toThrow(fn (UnusableHostnameException $e) => expect($e->hostname)->toBe($host));

    expect(hostnameFootprint())->toBe($before);
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'a dot' => ['.'],
    'single label' => ['localhost'],
    'an IP' => ['127.0.0.1'],
    'underscore' => ['a_b.test'],
    'a path' => ['a1.test/admin'],
]);
