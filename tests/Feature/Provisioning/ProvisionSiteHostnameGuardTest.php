<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\HostnameNotProvisionableException;
use App\Modules\Tenancy\Actions\AttachDomain;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * §2 cross-tenant guard: ProvisionSite stamps a new website's primary domain verified from birth,
 * which is only true of a name the platform owns. A customer's own domain that another website is
 * still proving (AttachDomain leaves it waiting) must never be taken this way — it would serve the
 * operator-made website on that customer's hostname and block them from ever verifying it.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, content_sections, site_features, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();
    Cache::flush();
    config([
        'tenancy.base_domains' => ['platform.test'],
        'tenancy.primary_base' => 'platform.test',
        'tenancy.platform_host' => 'console.platform.test',
        'tenancy.custom_domains.target' => 'target.platform.test',
    ]);
});

function hgProvision(string $host, string $tenant = 'Beta'): int
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $tenant,
        siteName: $tenant,
        hostname: $host,
        ownerEmail: 'owner-'.strtolower($tenant).'@example.test',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ))->id;
}

function hgReason(Closure $run): ?string
{
    try {
        $run();
    } catch (HostnameNotProvisionableException $e) {
        return $e->reason;
    }

    return null;
}

/** @return array<string, int> */
function hgFootprint(): array
{
    $admin = DB::connection('pgsql_admin');

    return collect(['tenants', 'sites', 'domains', 'users', 'tenant_users', 'model_has_roles'])
        ->mapWithKeys(fn (string $table): array => [$table => $admin->table($table)->count()])
        ->all();
}

/** Customer A's website waits on customer B's own domain (attached, not yet proved). */
function hgPendingCustomDomain(string $host): int
{
    $siteA = hgProvision('alpha.platform.test', 'Alpha');
    app(Tenancy::class)->enter($siteA, function () use ($host): void {
        SiteFeature::query()->updateOrCreate(['feature_key' => 'custom_domains.max'], ['value' => 1]);
        app(AttachDomain::class)->handle($host);
    });

    return $siteA;
}

it('refuses a customer domain another website is waiting on, and writes nothing', function (): void {
    hgPendingCustomDomain('shop.customer-b.com');
    $before = hgFootprint();

    expect(hgReason(fn () => hgProvision('shop.customer-b.com')))->toBe('not_platform_subdomain')
        ->and(hgFootprint())->toBe($before)
        ->and(DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.customer-b.com')->whereNotNull('verified_at')->exists())->toBeFalse();
});

it('refuses a name a website is still proving, even under a base added later', function (): void {
    // Customer A attached shop.newbase.test before newbase.test became a platform base.
    hgPendingCustomDomain('shop.newbase.test');
    config(['tenancy.base_domains' => ['platform.test', 'newbase.test']]);
    $before = hgFootprint();

    expect(hgReason(fn () => hgProvision('shop.newbase.test')))->toBe('pending_elsewhere')
        ->and(hgFootprint())->toBe($before);
});

it('still provisions a platform subdomain, verified and primary, and re-runs it', function (): void {
    $site = hgProvision('beta.platform.test');
    $again = hgProvision('beta.platform.test');

    $domain = DB::connection('pgsql_admin')->table('domains')->where('site_id', $site)->sole();

    expect($again)->toBe($site)
        ->and($domain->hostname)->toBe('beta.platform.test')
        ->and($domain->kind)->toBe('subdomain')
        ->and($domain->is_primary)->toBeTrue()
        ->and($domain->verified_at)->not->toBeNull();
});

it('stores what the operator typed in one spelling', function (): void {
    $site = hgProvision('HTTPS://Beta.Platform.TEST/lien-he');

    expect(DB::connection('pgsql_admin')->table('domains')->where('site_id', $site)->value('hostname'))->toBe('beta.platform.test')
        ->and(hgProvision('beta.platform.test'))->toBe($site);
});

it('refuses every name that is not one label under a platform base', function (string $host, string $reason): void {
    $before = hgFootprint();

    expect(hgReason(fn () => hgProvision($host)))->toBe($reason)
        ->and(hgFootprint())->toBe($before);
})->with([
    'a customer domain' => ['shop.example.com', 'not_platform_subdomain'],
    'the bare base' => ['platform.test', 'not_platform_subdomain'],
    'a nested name' => ['a.b.platform.test', 'not_platform_subdomain'],
    'the base\'s www' => ['www.platform.test', 'not_platform_subdomain'],
    'the console host' => ['console.platform.test', 'not_platform_subdomain'],
    'the pointing target' => ['target.platform.test', 'not_platform_subdomain'],
    'a look-alike suffix' => ['evilplatform.test', 'not_platform_subdomain'],
    'not a hostname' => ['not a host!', 'invalid'],
]);

it('knows which names are platform subdomains', function (string $host, bool $platform): void {
    expect(DomainName::isPlatformSubdomain($host))->toBe($platform);
})->with([
    ['beta.platform.test', true],
    ['platform.test', false],
    ['a.b.platform.test', false],
    ['www.platform.test', false],
    ['console.platform.test', false],
    ['evilplatform.test', false],
    ['beta.platform.test.evil.com', false],
]);

it('explains a refused hostname in plain words', function (): void {
    $this->artisan('site:provision', [
        '--name' => 'Beta',
        '--host' => 'shop.customer-b.com',
        '--owner-email' => 'owner@example.test',
    ])
        ->expectsQuestion('Owner password (leave blank to auto-generate)', '')
        ->expectsOutputToContain(__('provisioning.hostname_not_platform_subdomain', ['host' => 'shop.customer-b.com', 'example' => 'shop.platform.test']))
        ->assertFailed();

    expect(hgFootprint()['sites'])->toBe(0);
});
