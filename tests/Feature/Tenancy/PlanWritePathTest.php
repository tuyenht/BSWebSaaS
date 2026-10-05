<?php

use App\Modules\Admin\Support\PlatformPlans;
use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Plans are the platform's catalog (design.md §15.2): a named bundle of feature grants a customer
 * is put on. Staff who hold platform.billing.manage create a plan, rename it, stop selling it, set
 * what it grants, order and copy it, and move a customer onto it — giving a reason. No price:
 * billing is deferred. Every change names who made it.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();   // tenant 1 owns sites 1 (a.test) + 2 (b.test)
    DB::connection('pgsql_admin')->statement('truncate users, audit_logs restart identity cascade');
    DB::connection('pgsql_admin')->statement('delete from plans');
    DB::connection('pgsql_admin')->statement("delete from platform_settings where group_name = 'plans'");
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Cache::flush();
});

function pwStaff(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user;
}

/** @return array<string, bool|int> what the plan grants, by feature key */
function pwGrants(Plan $plan): array
{
    return PlanFeature::query()->where('plan_id', $plan->id)->orderBy('feature_key')->pluck('value', 'feature_key')->all();
}

function pwFeature(int $siteId, string $key): bool|int
{
    return app(Tenancy::class)->enter($siteId, fn (): bool|int => app(Entitlement::class)->featureValue($key));
}

it('creates a plan with a key made from its name, and never reuses a key', function (): void {
    $staff = pwStaff('billing');

    $first = app(PlatformPlans::class)->create($staff, 'Gói Chuyên Nghiệp');
    $second = app(PlatformPlans::class)->create($staff, 'Gói Chuyên Nghiệp');

    expect($first->key)->toBe('goi-chuyen-nghiep')
        ->and($first->is_active)->toBeTrue()
        ->and($second->key)->not->toBe($first->key)
        ->and($second->key)->toStartWith('goi-chuyen-nghiep-')
        ->and(AuditLog::query()->where('action', 'tenancy.plan.created')->where('actor_id', $staff->id)->count())->toBe(2);
});

it('renames a plan and stops selling it without changing its key or its customers', function (): void {
    $staff = pwStaff('billing');
    $plan = app(PlatformPlans::class)->create($staff, 'Pro');
    app(PlatformPlans::class)->setFeatures($staff, $plan, ['locales.max' => 3]);
    app(PlatformPlans::class)->changeCustomerPlan($staff, Tenant::query()->findOrFail(1), $plan, 'Ký hợp đồng');

    $plan = app(PlatformPlans::class)->update($staff, $plan, 'Pro 2026', false);

    expect($plan->only('key', 'name', 'is_active'))->toBe(['key' => 'pro', 'name' => 'Pro 2026', 'is_active' => false])
        ->and(pwFeature(1, 'locales.max'))->toBe(3);
});

it('refuses a plan with no name', function (): void {
    $staff = pwStaff('billing');
    $plan = app(PlatformPlans::class)->create($staff, 'Pro');

    expect(fn () => app(PlatformPlans::class)->create($staff, '   '))->toThrow(fn (PlanChangeRejectedException $e) => expect($e->reason)->toBe('name_required'))
        ->and(fn () => app(PlatformPlans::class)->update($staff, $plan, '', true))->toThrow(PlanChangeRejectedException::class);
    expect(Plan::query()->count())->toBe(1);
});

it('sets what a plan grants as one whole table, customer-wide features included', function (): void {
    $staff = pwStaff('billing');
    $plan = app(PlatformPlans::class)->create($staff, 'Pro');
    DB::table('tenants')->where('id', 1)->update(['plan_id' => $plan->id]);

    app(PlatformPlans::class)->setFeatures($staff, $plan, ['locales.max' => 3, 'sites.max' => 5, 'rbac.custom_roles' => true]);
    expect(pwGrants($plan))->toBe(['locales.max' => 3, 'rbac.custom_roles' => true, 'sites.max' => 5])
        ->and(pwFeature(1, 'locales.max'))->toBe(3)
        ->and(pwFeature(2, 'sites.max'))->toBe(5);

    // A feature left out of the table is no longer granted: the default applies again.
    app(PlatformPlans::class)->setFeatures($staff, $plan, ['locales.max' => 2]);
    expect(pwGrants($plan))->toBe(['locales.max' => 2])
        ->and(pwFeature(1, 'rbac.custom_roles'))->toBeFalse();

    $trail = AuditLog::query()->where('action', 'tenancy.plan.features_changed')->orderBy('id')->get();
    expect($trail)->toHaveCount(2)
        ->and($trail[1]->meta)->toMatchArray(['plan' => 'pro', 'features' => ['locales.max' => 2]]);
});

it('refuses a table holding a feature that does not exist or a value of the wrong kind, and changes nothing', function (array $features, string $reason): void {
    $staff = pwStaff('billing');
    $plan = app(PlatformPlans::class)->create($staff, 'Pro');
    app(PlatformPlans::class)->setFeatures($staff, $plan, ['locales.max' => 2]);

    expect(fn () => app(PlatformPlans::class)->setFeatures($staff, $plan, $features))
        ->toThrow(fn (FeatureChangeRejectedException $e) => expect($e->reason)->toBe($reason));
    expect(pwGrants($plan))->toBe(['locales.max' => 2]);
})->with([
    'unknown feature' => [['locales.max' => 3, 'nope.nope' => true], 'unknown_feature'],
    'a number for a switch' => [['rbac.custom_roles' => 2], 'wrong_kind'],
    'a switch for a limit' => [['locales.max' => true], 'wrong_kind'],
    'a negative limit' => [['sites.max' => -1], 'wrong_kind'],
]);

it('orders the plans as given, and refuses a list that is not exactly the plans', function (): void {
    $staff = pwStaff('billing');
    [$a, $b, $c] = [app(PlatformPlans::class)->create($staff, 'A'), app(PlatformPlans::class)->create($staff, 'B'), app(PlatformPlans::class)->create($staff, 'C')];

    app(PlatformPlans::class)->reorder($staff, [$c->id, $a->id, $b->id]);
    expect(Plan::query()->orderBy('sort_order')->orderBy('id')->pluck('key')->all())->toBe(['c', 'a', 'b']);

    expect(fn () => app(PlatformPlans::class)->reorder($staff, [$a->id, $b->id]))->toThrow(fn (PlanChangeRejectedException $e) => expect($e->reason)->toBe('not_all_plans'));
    expect(Plan::query()->orderBy('sort_order')->orderBy('id')->pluck('key')->all())->toBe(['c', 'a', 'b']);
});

it('copies a plan with what it grants; the copy is not on sale until someone decides', function (): void {
    $staff = pwStaff('billing');
    $plan = app(PlatformPlans::class)->create($staff, 'Pro');
    app(PlatformPlans::class)->setFeatures($staff, $plan, ['locales.max' => 3, 'rbac.custom_roles' => true]);

    $copy = app(PlatformPlans::class)->duplicate($staff, $plan, 'Pro Plus');

    expect($copy->id)->not->toBe($plan->id)
        ->and($copy->only('key', 'name', 'is_active'))->toBe(['key' => 'pro-plus', 'name' => 'Pro Plus', 'is_active' => false])
        ->and(pwGrants($copy))->toBe(pwGrants($plan))
        ->and(AuditLog::query()->where('action', 'tenancy.plan.duplicated')->value('meta'))->toMatchArray(['plan' => 'pro-plus', 'from' => 'pro']);
});

it('moves a customer onto a plan with a reason, and back to no plan', function (): void {
    $staff = pwStaff('billing');
    $tenant = Tenant::query()->findOrFail(1);
    $pro = app(PlatformPlans::class)->create($staff, 'Pro');
    app(PlatformPlans::class)->setFeatures($staff, $pro, ['locales.max' => 3]);

    app(PlatformPlans::class)->changeCustomerPlan($staff, $tenant, $pro, 'Ký hợp đồng năm');
    expect(pwFeature(1, 'locales.max'))->toBe(3)->and(pwFeature(2, 'locales.max'))->toBe(3);

    app(PlatformPlans::class)->changeCustomerPlan($staff, $tenant, null, 'Hết hợp đồng');
    expect(pwFeature(1, 'locales.max'))->toBe(1);

    $trail = AuditLog::query()->where('action', 'tenancy.customer.plan_changed')->orderBy('id')->get();
    expect($trail)->toHaveCount(2)
        ->and($trail[0]->actor_id)->toBe($staff->id)
        ->and($trail[0]->meta)->toMatchArray(['customer' => $tenant->ulid, 'to' => 'pro', 'reason' => 'Ký hợp đồng năm'])
        ->and($trail[1]->meta)->toMatchArray(['customer' => $tenant->ulid, 'from' => 'pro', 'reason' => 'Hết hợp đồng']);
});

it('refuses to move a customer without a reason, or onto a plan no longer sold', function (): void {
    $staff = pwStaff('billing');
    $tenant = Tenant::query()->findOrFail(1);
    $pro = app(PlatformPlans::class)->create($staff, 'Pro');
    $old = app(PlatformPlans::class)->update($staff, app(PlatformPlans::class)->create($staff, 'Old'), 'Old', false);

    expect(fn () => app(PlatformPlans::class)->changeCustomerPlan($staff, $tenant, $pro, '  '))->toThrow(fn (PlanChangeRejectedException $e) => expect($e->reason)->toBe('reason_required'))
        ->and(fn () => app(PlatformPlans::class)->changeCustomerPlan($staff, $tenant, $old, 'Thử'))->toThrow(fn (PlanChangeRejectedException $e) => expect($e->reason)->toBe('plan_not_sold'));
    expect(DB::table('tenants')->where('id', 1)->value('plan_id'))->toBeNull()
        ->and(AuditLog::query()->where('action', 'tenancy.customer.plan_changed')->count())->toBe(0);
});

it('lets only staff who manage billing touch plans', function (string $who): void {
    $billing = pwStaff('billing');
    $plan = app(PlatformPlans::class)->create($billing, 'Pro');
    $trailBefore = AuditLog::query()->count();
    $actor = $who === 'member' ? User::factory()->create() : pwStaff($who);
    $tenant = Tenant::query()->findOrFail(1);

    foreach ([
        fn () => app(PlatformPlans::class)->create($actor, 'X'),
        fn () => app(PlatformPlans::class)->update($actor, $plan, 'X', false),
        fn () => app(PlatformPlans::class)->setFeatures($actor, $plan, ['locales.max' => 9]),
        fn () => app(PlatformPlans::class)->reorder($actor, [$plan->id]),
        fn () => app(PlatformPlans::class)->duplicate($actor, $plan, 'X'),
        fn () => app(PlatformPlans::class)->changeCustomerPlan($actor, $tenant, $plan, 'X'),
    ] as $attempt) {
        expect($attempt)->toThrow(AuthorizationException::class);
    }

    expect(Plan::query()->pluck('name')->all())->toBe(['Pro'])
        ->and(pwGrants($plan))->toBe([])
        ->and(AuditLog::query()->count())->toBe($trailBefore);
})->with(['support', 'member']);

it('lets a super admin do all of it', function (): void {
    $staff = pwStaff('super_admin');

    $plan = app(PlatformPlans::class)->create($staff, 'Pro');
    app(PlatformPlans::class)->setFeatures($staff, $plan, ['locales.max' => 2]);
    app(PlatformPlans::class)->changeCustomerPlan($staff, Tenant::query()->findOrFail(1), $plan, 'Nâng gói');

    expect(pwFeature(1, 'locales.max'))->toBe(2);
});

it('ignores a website\'s own value for a customer-wide feature: only the plan sets it', function (): void {
    $staff = pwStaff('billing');
    $plan = app(PlatformPlans::class)->create($staff, 'Pro');
    app(PlatformPlans::class)->setFeatures($staff, $plan, ['sites.max' => 5]);
    DB::table('tenants')->where('id', 1)->update(['plan_id' => $plan->id]);

    // A row no write path can create any more (an old or hand-made one).
    app(Tenancy::class)->enter(1, fn () => SiteFeature::query()->create(['feature_key' => 'sites.max', 'value' => 99]));

    expect(pwFeature(1, 'sites.max'))->toBe(5);
});

it('keeps the upgrade invitation off until staff who manage billing switch it on', function (): void {
    $staff = pwStaff('billing');
    expect(app(UpgradeInvitation::class)->isOn())->toBeFalse();

    app(PlatformPlans::class)->setUpgradeInvitation($staff, true);
    expect(app(UpgradeInvitation::class)->isOn())->toBeTrue();

    app(PlatformPlans::class)->setUpgradeInvitation($staff, true);   // already on: nothing to record
    app(PlatformPlans::class)->setUpgradeInvitation($staff, false);
    expect(app(UpgradeInvitation::class)->isOn())->toBeFalse();

    $trail = AuditLog::query()->where('action', 'tenancy.plan.upgrade_invitation_changed')->orderBy('id')->get();
    expect($trail)->toHaveCount(2)
        ->and($trail[0]->actor_id)->toBe($staff->id)
        ->and($trail[0]->meta)->toMatchArray(['on' => true])
        ->and($trail[1]->meta)->toMatchArray(['on' => false]);
});

it('refuses the upgrade invitation switch to anyone who does not manage billing', function (string $who): void {
    $actor = $who === 'member' ? User::factory()->create() : pwStaff($who);

    expect(fn () => app(PlatformPlans::class)->setUpgradeInvitation($actor, true))->toThrow(AuthorizationException::class);
    expect(app(UpgradeInvitation::class)->isOn())->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['support', 'member']);

it('lets the operator switch the upgrade invitation from the console', function (): void {
    $this->artisan('platform:plan', ['--invitation' => 'on'])->expectsOutputToContain('Upgrade invitation: on')->assertSuccessful();
    expect(app(UpgradeInvitation::class)->isOn())->toBeTrue();

    $this->artisan('platform:plan', ['--invitation' => 'off'])->expectsOutputToContain('Upgrade invitation: off')->assertSuccessful();
    expect(app(UpgradeInvitation::class)->isOn())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'provisioning.plan.upgrade_invitation_changed')->count())->toBe(2);

    $this->artisan('platform:plan', ['--invitation' => 'maybe'])->expectsOutputToContain('on or off')->assertFailed();
});

it('lets the operator list, create, fill and assign a plan from the console', function (): void {
    $this->artisan('platform:plan', ['--create' => 'Pro'])->expectsOutputToContain('pro')->assertSuccessful();
    $this->artisan('platform:plan', ['plan' => 'pro', 'feature' => 'locales.max', 'value' => '3'])->assertSuccessful();
    $this->artisan('platform:plan', ['plan' => 'pro', 'feature' => 'rbac.custom_roles', 'value' => 'on'])->assertSuccessful();
    $this->artisan('platform:plan', ['plan' => 'pro', '--assign-to' => 'a.test', '--reason' => 'Ký hợp đồng'])->assertSuccessful();

    expect(pwGrants(Plan::query()->firstWhere('key', 'pro')))->toBe(['locales.max' => 3, 'rbac.custom_roles' => true])
        ->and(pwFeature(2, 'locales.max'))->toBe(3);

    $this->artisan('platform:plan', ['plan' => 'pro', 'feature' => 'rbac.custom_roles', '--clear' => true])->assertSuccessful();
    $this->artisan('platform:plan', ['plan' => 'pro', '--retire' => true])->assertSuccessful();
    $this->artisan('platform:plan')->expectsOutputToContain('pro')->assertSuccessful();

    expect(pwGrants(Plan::query()->firstWhere('key', 'pro')))->toBe(['locales.max' => 3])
        ->and(Plan::query()->firstWhere('key', 'pro')->is_active)->toBeFalse()
        ->and(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe([
            'provisioning.plan.created', 'provisioning.plan.features_changed', 'provisioning.plan.features_changed',
            'provisioning.customer.plan_changed', 'provisioning.plan.features_changed', 'provisioning.plan.updated',
        ]);
});

it('refuses a console change it cannot make, in words the operator can act on', function (array $arguments, string $says): void {
    $this->artisan('platform:plan', ['--create' => 'Pro'])->assertSuccessful();
    $this->artisan('platform:plan', ['--create' => 'Old'])->assertSuccessful();
    $this->artisan('platform:plan', ['plan' => 'old', '--retire' => true])->assertSuccessful();
    $trailBefore = AuditLog::query()->count();

    $this->artisan('platform:plan', $arguments)->expectsOutputToContain($says)->assertFailed();

    expect(AuditLog::query()->count())->toBe($trailBefore)
        ->and(DB::table('tenants')->where('id', 1)->value('plan_id'))->toBeNull();
})->with([
    'unknown plan' => [['plan' => 'nope', 'feature' => 'locales.max', 'value' => '2'], 'No plan'],
    'unknown feature' => [['plan' => 'pro', 'feature' => 'nope.nope', 'value' => 'on'], 'unknown_feature'],
    'wrong kind' => [['plan' => 'pro', 'feature' => 'locales.max', 'value' => 'on'], 'wrong_kind'],
    'assign without a reason' => [['plan' => 'pro', '--assign-to' => 'a.test'], 'reason_required'],
    'assign a plan no longer sold' => [['plan' => 'old', '--assign-to' => 'a.test', '--reason' => 'Thử'], 'plan_not_sold'],
    'assign to an unknown host' => [['plan' => 'pro', '--assign-to' => 'nowhere.test', '--reason' => 'Thử'], 'No site found'],
]);
