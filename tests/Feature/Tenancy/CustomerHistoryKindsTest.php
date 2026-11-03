<?php

use App\Modules\Admin\Support\SiteFeatures;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\SiteLimitReachedException;
use App\Modules\Provisioning\Exceptions\TenantSelectionException;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Actions\OverrideSiteFeature;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A customer's history tells every kind of change the customer page shows (design 03): the customer
 * created, each of its websites created, a website given its own value for a feature (or handed
 * back to its plan), the customer suspended or resumed, and a change of plan. Each row is written
 * in the same transaction as the change it tells of, so a change that fails or is refused leaves
 * none; a website is named by its ulid and its name at the time, never its bigint id.
 */

beforeEach(function (): void {
    truncateRbac();
    $admin = DB::connection('pgsql_admin');
    $admin->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         site_locales, site_modules, site_features, content_sections, domains, sites, tenants,
         tenant_history, tenant_users, users, audit_logs restart identity cascade'
    );
    $admin->statement('delete from plans');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Cache::flush();
});

function chkProvision(string $host, ?string $newTenant = null, ?int $tenantId = null, string $tenantName = ''): Site
{
    return app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $tenantName,
        siteName: 'Web '.$host,
        hostname: $host,
        ownerEmail: 'owner@'.$host,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-password',
        tenantId: $tenantId,
        newTenantName: $newTenant,
    ));
}

/**
 * The rows oldest first, meta in key order (JSONB keeps its own).
 *
 * @return list<array<string, mixed>>
 */
function chkRows(): array
{
    return TenantHistory::query()->orderBy('id')->get()->map(function (TenantHistory $r): array {
        $row = $r->only('tenant_id', 'kind', 'to_plan_id', 'actor_user_id', 'meta');
        if (is_array($row['meta'])) {
            ksort($row['meta']);
        }

        return $row;
    })->all();
}

function chkStaff(): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    return $user;
}

it('takes every kind the customer page shows, and still refuses one it does not list', function (string $kind): void {
    DB::connection('pgsql_admin')->table('tenants')->insert(['ulid' => 'TENANT0000000000000000001', 'name' => 'T1', 'settings' => '{}']);
    $id = DB::connection('pgsql_admin')->table('tenants')->value('id');

    DB::connection('pgsql_admin')->table('tenant_history')->insert(['tenant_id' => $id, 'kind' => $kind, 'meta' => json_encode(['site' => 'X'])]);

    expect(TenantHistory::query()->sole()->kind)->toBe($kind)
        ->and(fn () => DB::connection('pgsql_admin')->table('tenant_history')->insert(['tenant_id' => $id, 'kind' => 'renamed']))
        ->toThrow(QueryException::class, 'tenant_history_kind_check');
})->with(TenantHistory::KINDS);

it('can be rolled back to plan changes only, and forward again', function (): void {
    $migration = require database_path('migrations/2049_01_01_000000_add_customer_history_kinds.php');
    $admin = DB::connection('pgsql_admin');
    $admin->table('tenants')->insert(['ulid' => 'TENANT0000000000000000001', 'name' => 'T1', 'settings' => '{}']);
    $id = $admin->table('tenants')->value('id');
    $admin->table('tenant_history')->insert([['tenant_id' => $id, 'kind' => 'plan_changed'], ['tenant_id' => $id, 'kind' => 'site_created']]);
    // a refused insert inside a savepoint, so the open transaction carries on
    $insert = fn (string $kind) => $admin->transaction(fn () => $admin->table('tenant_history')->insert(['tenant_id' => $id, 'kind' => $kind]));

    // All of it inside one transaction that is rolled back: the shared database never sees meta
    // dropped and added again (other sessions' cached plans of the table stay valid).
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    $admin->beginTransaction();
    try {
        $migration->down();
        $afterDown = [$admin->table('tenant_history')->pluck('kind')->all(), Schema::connection('pgsql_admin')->hasColumn('tenant_history', 'meta')];
        expect(fn () => $insert('site_created'))->toThrow(QueryException::class, 'tenant_history_kind_check');

        $migration->up();
        $migration->up();   // re-runnable after a mid-run failure
        $insert('suspended');
        $afterUp = [$admin->table('tenant_history')->orderBy('id')->pluck('kind')->all(), Schema::connection('pgsql_admin')->hasColumn('tenant_history', 'meta')];
    } finally {
        $admin->rollBack();
        DB::setDefaultConnection($original);
    }

    expect($afterDown)->toBe([['plan_changed'], false])
        ->and($afterUp)->toBe([['plan_changed', 'suspended'], true]);
});

it('records a new customer and its first website once each, with no person behind them', function (): void {
    $site = chkProvision('a1.platform.test', newTenant: 'Cơ khí An Vinh');
    // a re-run creates nothing, so records nothing
    chkProvision('a1.platform.test', tenantId: $site->tenant_id);

    expect(chkRows())->toBe([
        ['tenant_id' => $site->tenant_id, 'kind' => 'customer_created', 'to_plan_id' => null, 'actor_user_id' => null, 'meta' => null],
        ['tenant_id' => $site->tenant_id, 'kind' => 'site_created', 'to_plan_id' => null, 'actor_user_id' => null,
            'meta' => ['site' => $site->ulid, 'site_name' => 'Web a1.platform.test']],
    ]);
});

it('records only the website for a second website of an existing customer', function (): void {
    $first = chkProvision('a1.platform.test', tenantName: 'Alpha');
    $plan = Plan::query()->create(['key' => 'pro', 'name' => 'Pro', 'is_active' => true, 'sort_order' => 1]);
    PlanFeature::query()->create(['plan_id' => $plan->id, 'feature_key' => 'sites.max', 'value' => 2]);
    Tenant::query()->whereKey($first->tenant_id)->update(['plan_id' => $plan->id]);

    $second = chkProvision('a2.platform.test', tenantId: $first->tenant_id);

    expect(array_column(chkRows(), 'kind'))->toBe(['customer_created', 'site_created', 'site_created'])
        ->and(chkRows()[2]['meta'])->toBe(['site' => $second->ulid, 'site_name' => 'Web a2.platform.test'])
        ->and(TenantHistory::query()->where('kind', 'customer_created')->sole()->tenant_id)->toBe($first->tenant_id);
});

it('records the plan a customer starts on', function (): void {
    $basic = Plan::query()->create(['key' => 'basic', 'name' => 'Cơ bản', 'is_active' => true, 'sort_order' => 1]);
    // no path gives a new customer a plan yet: stand in for one
    Tenant::creating(function (Tenant $t) use ($basic): void {
        $t->setAttribute('plan_id', $basic->id);
    });

    chkProvision('a1.platform.test', newTenant: 'Alpha');

    expect(TenantHistory::query()->where('kind', 'customer_created')->sole()->to_plan_id)->toBe($basic->id);
});

it('records nothing when provisioning is refused or fails before the website exists', function (): void {
    $first = chkProvision('a1.platform.test', tenantName: 'Alpha');
    $before = chkRows();

    // past the customer's limit
    expect(fn () => chkProvision('a2.platform.test', tenantId: $first->tenant_id))->toThrow(SiteLimitReachedException::class);
    // a namesake customer
    expect(fn () => chkProvision('b1.platform.test', newTenant: 'alpha'))->toThrow(TenantSelectionException::class);

    expect(chkRows())->toBe($before);
});

it('takes the new customer and its history back together when the run fails midway', function (): void {
    TenantHistory::created(function (TenantHistory $row): void {
        if ($row->kind === TenantHistory::KIND_SITE_CREATED) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => chkProvision('a1.platform.test', newTenant: 'Alpha'))->toThrow(RuntimeException::class, 'boom');

    expect(TenantHistory::query()->count())->toBe(0)
        ->and(Tenant::query()->withTrashed()->count())->toBe(0)
        ->and(Site::query()->withTrashed()->count())->toBe(0);
});

it('records a website given its own value, and handed back to its plan, naming who did it', function (): void {
    seedTenancy();   // tenant 1 owns sites 1 (A) + 2 (B)
    $staff = chkStaff();
    $site = Site::query()->findOrFail(1);

    app(SiteFeatures::class)->override($staff, $site, 'locales.max', 5);
    app(SiteFeatures::class)->override($staff, $site, 'locales.max', 5);   // the same value: nothing changes
    app(SiteFeatures::class)->override($staff, $site, 'rbac.custom_roles', true);
    app(SiteFeatures::class)->clear($staff, $site, 'locales.max');
    app(SiteFeatures::class)->clear($staff, $site, 'locales.max');   // nothing left to clear

    $meta = fn (string $feature, bool|int|null $value): array => ['feature' => $feature, 'site' => 'SITEA000000000000000000001', 'site_name' => 'A', 'value' => $value];
    expect(chkRows())->toBe([
        ['tenant_id' => 1, 'kind' => 'feature_overridden', 'to_plan_id' => null, 'actor_user_id' => $staff->id, 'meta' => $meta('locales.max', 5)],
        ['tenant_id' => 1, 'kind' => 'feature_overridden', 'to_plan_id' => null, 'actor_user_id' => $staff->id, 'meta' => $meta('rbac.custom_roles', true)],
        ['tenant_id' => 1, 'kind' => 'feature_overridden', 'to_plan_id' => null, 'actor_user_id' => $staff->id, 'meta' => $meta('locales.max', null)],
    ]);
});

it('records an override made from the console with no person behind it', function (): void {
    seedTenancy();

    app(Tenancy::class)->enter(2, fn () => app(OverrideSiteFeature::class)->handle('media.storage_mb', 2000));

    expect(chkRows())->toBe([['tenant_id' => 1, 'kind' => 'feature_overridden', 'to_plan_id' => null, 'actor_user_id' => null,
        'meta' => ['feature' => 'media.storage_mb', 'site' => 'SITEB000000000000000000001', 'site_name' => 'B', 'value' => 2000]]]);
});

it('records the overrides the site:feature and site:locale commands make, with no person behind them', function (): void {
    seedTenancy();

    $this->artisan('site:feature', ['host' => 'a.test', 'feature' => 'media.storage_mb', 'value' => '2000'])->assertSuccessful();
    $this->artisan('site:feature', ['host' => 'a.test', 'feature' => 'media.storage_mb', '--clear' => true])->assertSuccessful();
    $this->artisan('site:locale', ['host' => 'b.test', '--limit' => '4'])->assertSuccessful();

    $meta = fn (string $site, string $name, string $feature, ?int $value): array => ['feature' => $feature, 'site' => $site, 'site_name' => $name, 'value' => $value];
    expect(chkRows())->toBe([
        ['tenant_id' => 1, 'kind' => 'feature_overridden', 'to_plan_id' => null, 'actor_user_id' => null, 'meta' => $meta('SITEA000000000000000000001', 'A', 'media.storage_mb', 2000)],
        ['tenant_id' => 1, 'kind' => 'feature_overridden', 'to_plan_id' => null, 'actor_user_id' => null, 'meta' => $meta('SITEA000000000000000000001', 'A', 'media.storage_mb', null)],
        ['tenant_id' => 1, 'kind' => 'feature_overridden', 'to_plan_id' => null, 'actor_user_id' => null, 'meta' => $meta('SITEB000000000000000000001', 'B', 'locales.max', 4)],
    ]);
});

it('clears a stored value of an unexpected shape, and records it', function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->table('site_features')->insert(['site_id' => 1, 'feature_key' => 'locales.max', 'value' => json_encode('five'), 'created_at' => now(), 'updated_at' => now()]);

    app(Tenancy::class)->enter(1, fn () => app(OverrideSiteFeature::class)->handle('locales.max', null));

    expect(DB::connection('pgsql_admin')->table('site_features')->where('site_id', 1)->count())->toBe(0)
        ->and(array_column(chkRows(), 'kind'))->toBe(['feature_overridden']);
});

it('records nothing for a refused override', function (): void {
    seedTenancy();
    $site = Site::query()->findOrFail(1);

    expect(fn () => app(SiteFeatures::class)->override(chkStaff(), $site, 'sites.max', 5))->toThrow(FeatureChangeRejectedException::class)
        ->and(fn () => app(SiteFeatures::class)->override(chkStaff(), $site, 'locales.max', -1))->toThrow(FeatureChangeRejectedException::class)
        ->and(fn () => app(SiteFeatures::class)->override(User::factory()->create(), $site, 'locales.max', 4))->toThrow(AuthorizationException::class);

    expect(TenantHistory::query()->count())->toBe(0);
});
