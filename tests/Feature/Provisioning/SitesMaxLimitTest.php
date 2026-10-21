<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\SiteLimitReachedException;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * `sites.max` (FeatureRegistry) is the customer-wide cap on how many websites one tenant may have.
 * ProvisionSite is the single path that creates a site (site:provision calls it), so the cap is
 * checked there: a NEW site past the cap is refused; re-running provisioning for a site that
 * already exists is not a new site and is never refused. The cap is the plan's alone — a
 * per-website override cannot lift a customer-wide limit.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, site_features, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    DB::connection('pgsql_admin')->statement('delete from plans');
    truncateRbac();
});

function smProvision(string $tenant, string $host): Site
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $tenant,
        siteName: $host,
        hostname: $host,
        ownerEmail: 'owner@'.$host,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
    ));
}

/** Put the tenant named $tenant on a plan granting sites.max = $limit. */
function smGrant(string $tenant, int $limit): void
{
    $plan = Plan::query()->create(['key' => 'p'.$limit.strtolower(Str::random(6)), 'name' => 'Plan '.$limit, 'is_active' => true, 'sort_order' => 1]);
    PlanFeature::query()->create(['plan_id' => $plan->id, 'feature_key' => 'sites.max', 'value' => $limit]);
    Tenant::query()->firstOrCreate(['name' => $tenant])->forceFill(['plan_id' => $plan->id])->save();
}

function smRefusal(Closure $provision): SiteLimitReachedException
{
    try {
        $provision();
    } catch (SiteLimitReachedException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the site limit to refuse the new website.');
}

it('creates a website while the customer is under its limit', function (): void {
    $site = smProvision('Alpha', 'a1.test');

    expect($site->status)->toBe('active')
        ->and(Site::query()->where('tenant_id', $site->tenant_id)->count())->toBe(1);
});

it('refuses a new website once the customer holds the default limit of one, leaving no trace', function (): void {
    smProvision('Alpha', 'a1.test');

    $refusal = smRefusal(fn () => smProvision('Alpha', 'a2.test'));

    expect($refusal->count)->toBe(1)
        ->and($refusal->limit)->toBe(1)
        ->and(Site::query()->count())->toBe(1)
        ->and(Domain::query()->where('hostname', 'a2.test')->exists())->toBeFalse();
});

it('refuses a new website for a customer chosen by id too', function (): void {
    $first = smProvision('Alpha', 'a1.test');

    smRefusal(fn () => app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'ignored', siteName: 'A2', hostname: 'a2.test',
        ownerEmail: 'owner@a2.test', ownerName: 'Owner', ownerPassword: 'a-strong-password',
        tenantId: $first->tenant_id,
    )));

    expect(Site::query()->count())->toBe(1);
});

it('lets a plan that raises the limit create more websites, up to the new limit', function (): void {
    smGrant('Alpha', 2);

    smProvision('Alpha', 'a1.test');
    smProvision('Alpha', 'a2.test');
    $refusal = smRefusal(fn () => smProvision('Alpha', 'a3.test'));

    expect($refusal->count)->toBe(2)
        ->and($refusal->limit)->toBe(2)
        ->and(Site::query()->count())->toBe(2);
});

it('does not count another customer\'s websites', function (): void {
    smGrant('Beta', 3);
    smProvision('Beta', 'b1.test');
    smProvision('Beta', 'b2.test');
    smProvision('Beta', 'b3.test');

    $site = smProvision('Alpha', 'a1.test');

    expect($site->status)->toBe('active')
        ->and(Site::query()->count())->toBe(4);
});

it('never refuses re-running provisioning for a website that already exists', function (): void {
    $first = smProvision('Alpha', 'a1.test');

    $again = smProvision('Alpha', 'a1.test');

    expect($again->id)->toBe($first->id)
        ->and(Site::query()->count())->toBe(1);
});

it('ignores a per-website override: the limit is the plan\'s alone', function (): void {
    $first = smProvision('Alpha', 'a1.test');
    app(Tenancy::class)->enter($first->id, fn () => SiteFeature::query()->create(['feature_key' => 'sites.max', 'value' => 99]));

    smRefusal(fn () => smProvision('Alpha', 'a2.test'));

    expect(Site::query()->count())->toBe(1);
});

it('refuses every new website when the plan grants zero', function (): void {
    smGrant('Alpha', 0);

    $refusal = smRefusal(fn () => smProvision('Alpha', 'a1.test'));

    expect($refusal->count)->toBe(0)
        ->and($refusal->limit)->toBe(0)
        ->and(Site::query()->count())->toBe(0);
});

it('frees the slot of a deleted website', function (): void {
    smProvision('Alpha', 'a1.test')->delete();

    $site = smProvision('Alpha', 'a2.test');

    expect($site->status)->toBe('active');
});

it('refuses through site:provision with a plain-language message', function (): void {
    smProvision('Alpha', 'a1.test');
    // A missing translation would echo the key back and still match the output below.
    expect(__('provisioning.sites_limit_reached'))->not->toBe('provisioning.sites_limit_reached');

    $this->artisan('site:provision', ['--name' => 'A2', '--host' => 'a2.test', '--tenant' => 'Alpha', '--owner-email' => 'owner@a2.test'])
        ->expectsQuestion('Owner password (leave blank to auto-generate)', '')
        ->expectsOutputToContain(__('provisioning.sites_limit_reached', ['count' => 1, 'limit' => 1]))
        ->assertFailed();

    expect(Site::query()->count())->toBe(1);
});
