<?php

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Storefront\EdgeCache\PurgeEdgeHosts;
use App\Modules\Storefront\EdgeCache\PurgePlanEdgeCache;
use App\Modules\Storefront\EdgeCache\PurgeSiteEdgeCache;
use App\Modules\Tenancy\Actions\AttachDomain;
use App\Modules\Tenancy\Actions\ChangeCustomerPlan;
use App\Modules\Tenancy\Actions\CheckDomain;
use App\Modules\Tenancy\Actions\ChooseRunningSpelling;
use App\Modules\Tenancy\Actions\RemoveDomain;
use App\Modules\Tenancy\Actions\SavePlan;
use App\Modules\Tenancy\Actions\SetPlanFeatures;
use App\Modules\Tenancy\Actions\SetPrimaryDomain;
use App\Modules\Tenancy\Context\SiteDirectory;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\OwnershipProof;
use App\Modules\Tenancy\Events\DomainNeedsAttention;
use App\Modules\Tenancy\Events\PublicSiteChanged;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Fixtures\FakeDns;

/*
 * Changes made outside a website's tenant context still change its public pages: where they answer
 * (a new primary domain, the other spelling running, a domain removed, lost or taken over), what its
 * plan lets it show, its status. Each queues the purge of the websites concerned once it commits; a
 * change that rolls back purges nothing.
 */

beforeEach(function (): void {
    seedTenancy(); // tenant 1 owns sites 1 (a.test) and 2 (b.test)
    DB::connection('pgsql_admin')->statement('delete from plans');
    Cache::flush();
    Notification::fake();
    edgeCacheRecorder();
    Bus::fake([PurgeSiteEdgeCache::class, PurgePlanEdgeCache::class, PurgeEdgeHosts::class]);
    // site 1's own domain, live with HTTPS on, in both spellings; the one without www runs
    DB::connection('pgsql_admin')->table('domains')->insert([
        ['site_id' => 1, 'hostname' => 'congty-an.vn', 'kind' => 'custom', 'is_primary' => false, 'is_redirect' => false, 'verified_at' => now(), 'ssl_status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 1, 'hostname' => 'www.congty-an.vn', 'kind' => 'custom', 'is_primary' => false, 'is_redirect' => true, 'verified_at' => now(), 'ssl_status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/** @return list<array{0: int, 1: list<string>}> each queued site purge: the site, the hosts it no longer has */
function sitePurgesQueued(): array
{
    return Bus::dispatched(PurgeSiteEdgeCache::class)->map(function (PurgeSiteEdgeCache $job): array {
        $also = $job->alsoHosts;
        sort($also);

        return [$job->siteId, $also];
    })->values()->all();
}

it('purges the site once a change to where its pages answer commits, never before', function (Closure $change, array $gone): void {
    app(Tenancy::class)->enter(1, function () use ($change): void {
        $change();
        expect(sitePurgesQueued())->toBe([]); // still inside the transaction
    });

    expect(sitePurgesQueued())->toBe([[1, $gone]]);
})->with([
    'a new primary domain' => [fn () => app(SetPrimaryDomain::class)->handle('congty-an.vn'), []],
    'the other spelling runs' => [fn () => app(ChooseRunningSpelling::class)->handle('www.congty-an.vn'), []],
    'a domain removed' => [fn () => app(RemoveDomain::class)->handle('congty-an.vn'), ['congty-an.vn', 'www.congty-an.vn']],
]);

it('purges nothing for a domain change that rolls back, or one that changes nothing', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        try {
            DB::transaction(function (): void {
                app(SetPrimaryDomain::class)->handle('congty-an.vn');
                throw new RuntimeException('Hủy giữa chừng');
            });
        } catch (RuntimeException) {
        }
        app(SetPrimaryDomain::class)->handle('a.test');                 // already the primary
        app(ChooseRunningSpelling::class)->handle('congty-an.vn');      // already the one that runs
    });
    app(Tenancy::class)->enter(2, fn () => app(AttachDomain::class)->handle('cho-xac-minh.vn')); // waits: not served yet

    expect(sitePurgesQueued())->toBe([]);
});

it('purges a site whose pages move with a domain notice, and only those notices', function (string $variant, ?array $gone): void {
    event(new DomainNeedsAttention(2, $variant, ['congty-binh.vn']));

    expect(sitePurgesQueued())->toBe($gone === null ? [] : [[2, $gone]]);
})->with([
    'a live name starts failing' => ['failing', []],
    'a failing name is back' => ['recovered', []],
    'a name lost after failing' => ['lost', ['congty-binh.vn']],
    'a name taken over by another site' => ['taken_over', ['congty-binh.vn']],
    'a reminder before losing it' => ['expiring', null],
    'a name that never pointed here dropped' => ['wait_expired', null],
]);

it('purges the site that loses its live domain to the owner who proves it', function (): void {
    config(['tenancy.custom_domains.target' => 'target.platform.test']);
    $dns = FakeDns::install();
    DB::connection('pgsql_admin')->table('domains')->insert([
        'site_id' => 1, 'hostname' => 'shop.example', 'kind' => 'custom', 'is_primary' => false, 'is_redirect' => false,
        'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(Tenancy::class)->enter(2, fn () => app(AttachDomain::class)->handle('shop.example'));
    $dns->pointHere('shop.example');
    $dns->txt['_site-verification.shop.example'] = [app(OwnershipProof::class)->recordValue(2, 'shop.example')];

    $check = app(Tenancy::class)->enter(2, fn () => app(CheckDomain::class)->handle('shop.example'));

    expect($check->evictedSiteIds)->toBe([1])
        ->and(sitePurgesQueued())->toBe([[1, ['shop.example']]]);
});

it('purges every website of a customer moved to another plan, after the move commits', function (): void {
    $plan = app(SavePlan::class)->handle(null, 'Gói Doanh nghiệp', true);
    $customer = Tenant::query()->findOrFail(1);

    try {
        DB::transaction(function () use ($customer, $plan): void {
            app(ChangeCustomerPlan::class)->handle($customer, $plan, 'Thử rồi hủy');
            throw new RuntimeException('Hủy giữa chừng');
        });
    } catch (RuntimeException) {
    }
    expect(sitePurgesQueued())->toBe([]);

    app(ChangeCustomerPlan::class)->handle($customer->refresh(), $plan, 'Khách nâng gói');
    expect(sitePurgesQueued())->toBe([[1, []], [2, []]]);

    app(ChangeCustomerPlan::class)->handle($customer, $plan, 'Bấm lại lần nữa'); // already on it
    expect(sitePurgesQueued())->toBe([[1, []], [2, []]]);
});

it('purges every website on a plan whose grants change, 30 host names a call, and none on another plan', function (): void {
    $plan = app(SavePlan::class)->handle(null, 'Gói Cơ bản', true);
    $admin = DB::connection('pgsql_admin');
    $admin->table('tenants')->where('id', 1)->update(['plan_id' => $plan->id]);
    $admin->table('tenants')->insert(['ulid' => 'TENANT0000000000000000002', 'name' => 'T2', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $admin->table('sites')->insert(['ulid' => 'SITEC000000000000000000001', 'tenant_id' => 2, 'name' => 'C', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $admin->table('domains')->insert(['site_id' => 3, 'hostname' => 'c.test', 'kind' => 'subdomain', 'is_primary' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    app(SetPlanFeatures::class)->handle($plan, ['locales.max' => 3]);

    Bus::assertDispatched(PurgePlanEdgeCache::class, fn (PurgePlanEdgeCache $job): bool => $job->planId === $plan->id);
    (new PurgePlanEdgeCache($plan->id))->handle(app(SiteDirectory::class));

    // the customer domains sit outside the zone; site 3's customer is on no plan
    expect(Bus::dispatched(PurgeEdgeHosts::class)->map(fn (PurgeEdgeHosts $job): array => $job->hosts)->all())->toBe([['a.test', 'b.test']]);
});

it('purges a new website once it opens', function (): void {
    DB::connection('pgsql_admin')->statement('truncate users, tenant_users restart identity cascade');
    truncateRbac();
    config([
        'tenancy.base_domains' => ['platform.test'], 'tenancy.primary_base' => 'platform.test', 'tenancy.platform_host' => 'console.platform.test',
        'tenancy.custom_domains.target' => 'target.platform.test', 'media.cloudflare.base' => 'https://files.platform.test/',
    ]);
    Event::fake([PublicSiteChanged::class]);

    $site = app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Công ty Mới', siteName: 'Công ty Mới', hostname: 'moi.platform.test',
        ownerEmail: 'chu@moi.test', ownerName: 'Chủ', ownerPassword: 'a-strong-password',
    ));

    Event::assertDispatchedTimes(PublicSiteChanged::class, 1);
    Event::assertDispatched(PublicSiteChanged::class, fn (PublicSiteChanged $event): bool => $event->siteId === $site->id && $event->alsoHosts === []);
});
