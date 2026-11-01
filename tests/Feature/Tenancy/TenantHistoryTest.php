<?php

use App\Modules\Admin\Support\PlatformPlans;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Actions\ChangeCustomerPlan;
use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantHistory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * A customer's history (tenant_history) is a platform-only record of what happened to the
 * customer — for now, each change of plan: from which plan, to which, why and by whom. It is
 * written in the same transaction as the change itself, so the two never disagree. Plans are
 * never deleted while a customer's history names them; a person who made a change may be removed
 * and the row keeps everything but their name.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();   // tenant 1 owns sites 1 (a.test) + 2 (b.test)
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate tenant_history, users, audit_logs restart identity cascade');
    $admin->statement('delete from plans');
    $admin->statement("delete from platform_settings where group_name = 'plans'");
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Cache::flush();
});

function thStaff(): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('billing'));
    app(PlatformAccess::class)->forgetMemo();

    return $user;
}

function thPlan(string $key, bool $sold = true): Plan
{
    return Plan::query()->create(['key' => $key, 'name' => ucfirst($key), 'is_active' => $sold]);
}

it('records each change of plan with where from, where to, why and who', function (): void {
    $staff = thStaff();
    $tenant = Tenant::query()->findOrFail(1);
    $basic = thPlan('basic');
    $pro = thPlan('pro');

    app(PlatformPlans::class)->changeCustomerPlan($staff, $tenant, $basic, 'Ký hợp đồng');
    app(PlatformPlans::class)->changeCustomerPlan($staff, $tenant, $pro, '  Nâng gói  ');
    app(PlatformPlans::class)->changeCustomerPlan($staff, $tenant, null, 'Hết hợp đồng');

    $rows = TenantHistory::query()->orderBy('id')->get();

    expect($rows->map(fn (TenantHistory $row): array => $row->only('tenant_id', 'kind', 'from_plan_id', 'to_plan_id', 'reason', 'actor_user_id'))->all())->toBe([
        ['tenant_id' => 1, 'kind' => 'plan_changed', 'from_plan_id' => null, 'to_plan_id' => $basic->id, 'reason' => 'Ký hợp đồng', 'actor_user_id' => $staff->id],
        ['tenant_id' => 1, 'kind' => 'plan_changed', 'from_plan_id' => $basic->id, 'to_plan_id' => $pro->id, 'reason' => 'Nâng gói', 'actor_user_id' => $staff->id],
        ['tenant_id' => 1, 'kind' => 'plan_changed', 'from_plan_id' => $pro->id, 'to_plan_id' => null, 'reason' => 'Hết hợp đồng', 'actor_user_id' => $staff->id],
    ])
        ->and($rows[0]->created_at)->not->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'tenancy.customer.plan_changed')->count())->toBe(3);
});

it('records a change made from the console with no person behind it', function (): void {
    thPlan('pro');

    $this->artisan('platform:plan', ['plan' => 'pro', '--assign-to' => 'a.test', '--reason' => 'Theo hợp đồng'])->assertSuccessful();

    expect(TenantHistory::query()->sole()->only('tenant_id', 'to_plan_id', 'reason', 'actor_user_id'))
        ->toBe(['tenant_id' => 1, 'to_plan_id' => Plan::query()->where('key', 'pro')->value('id'), 'reason' => 'Theo hợp đồng', 'actor_user_id' => null]);
});

it('records nothing when the customer stays on the same plan', function (): void {
    $staff = thStaff();
    $tenant = Tenant::query()->findOrFail(1);
    $pro = thPlan('pro');

    app(PlatformPlans::class)->changeCustomerPlan($staff, $tenant, $pro, 'Ký hợp đồng');
    app(PlatformPlans::class)->changeCustomerPlan($staff, $tenant, $pro, 'Bấm lại');

    expect(TenantHistory::query()->count())->toBe(1);
});

it('records nothing for a refused change', function (): void {
    $staff = thStaff();
    $tenant = Tenant::query()->findOrFail(1);
    $old = thPlan('old', sold: false);

    expect(fn () => app(PlatformPlans::class)->changeCustomerPlan($staff, $tenant, $old, 'Thử'))->toThrow(PlanChangeRejectedException::class)
        ->and(fn () => app(PlatformPlans::class)->changeCustomerPlan($staff, $tenant, thPlan('pro'), str_repeat('a', 501)))
        ->toThrow(fn (PlanChangeRejectedException $e) => expect($e->reason)->toBe('reason_required'));

    expect(TenantHistory::query()->count())->toBe(0)
        ->and(DB::table('tenants')->where('id', 1)->value('plan_id'))->toBeNull();
});

it('takes a reason of exactly 500 characters', function (): void {
    app(PlatformPlans::class)->changeCustomerPlan(thStaff(), Tenant::query()->findOrFail(1), thPlan('pro'), str_repeat('ă', 500));

    expect(TenantHistory::query()->sole()->reason)->toBe(str_repeat('ă', 500));
});

it('writes the history row in the same transaction as the plan change', function (): void {
    $tenant = Tenant::query()->findOrFail(1);
    $pro = thPlan('pro');

    // A reason the column cannot hold (the callers refuse it first) makes the history insert fail:
    // the plan change must go with it.
    expect(fn () => app(ChangeCustomerPlan::class)->handle($tenant, $pro, str_repeat('a', 501)))->toThrow(QueryException::class);

    expect(DB::table('tenants')->where('id', 1)->value('plan_id'))->toBeNull()
        ->and(TenantHistory::query()->count())->toBe(0);
});

it('reads the plan the customer is on now, not the one a stale copy remembers', function (): void {
    $basic = thPlan('basic');
    $pro = thPlan('pro');
    $stale = Tenant::query()->findOrFail(1);   // plan_id null
    app(ChangeCustomerPlan::class)->handle(Tenant::query()->findOrFail(1), $basic, 'Ký hợp đồng');

    $was = app(ChangeCustomerPlan::class)->handle($stale, $pro, 'Nâng gói');

    expect($was)->toBe($basic->id)
        ->and(TenantHistory::query()->orderByDesc('id')->firstOrFail()->from_plan_id)->toBe($basic->id)
        ->and(DB::table('tenants')->where('id', 1)->value('plan_id'))->toBe($pro->id);
});

it('takes no kind but the ones it lists', function (): void {
    expect(fn () => DB::connection('pgsql_admin')->table('tenant_history')->insert(['tenant_id' => 1, 'kind' => 'renamed']))
        ->toThrow(QueryException::class, 'tenant_history_kind_check');
});

it('keeps a plan that a customer history names from being deleted', function (string $side): void {
    $pro = thPlan('pro');
    DB::connection('pgsql_admin')->table('tenant_history')->insert(['tenant_id' => 1, 'kind' => 'plan_changed', $side => $pro->id]);

    expect(fn () => DB::connection('pgsql_admin')->table('plans')->where('id', $pro->id)->delete())
        ->toThrow(QueryException::class, 'tenant_history_'.$side.'_foreign');
})->with(['from_plan_id', 'to_plan_id']);

it('keeps the row and forgets the person when the person is removed', function (): void {
    $staff = thStaff();
    app(PlatformPlans::class)->changeCustomerPlan($staff, Tenant::query()->findOrFail(1), thPlan('pro'), 'Ký hợp đồng');

    DB::connection('pgsql_admin')->table('users')->where('id', $staff->id)->delete();

    expect(TenantHistory::query()->sole()->only('actor_user_id', 'reason'))->toBe(['actor_user_id' => null, 'reason' => 'Ký hợp đồng']);
});

it('goes with the customer when the customer is removed for good', function (): void {
    app(PlatformPlans::class)->changeCustomerPlan(thStaff(), Tenant::query()->findOrFail(1), thPlan('pro'), 'Ký hợp đồng');

    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->delete();

    expect(TenantHistory::query()->count())->toBe(0);
});

it('reads a customer history newest first from its own index', function (): void {
    $index = DB::selectOne("select pg_get_indexdef('tenant_history_tenant_id_created_at_id_index'::regclass) as def")->def;

    expect($index)->toBe('CREATE INDEX tenant_history_tenant_id_created_at_id_index ON public.tenant_history USING btree (tenant_id, created_at DESC, id DESC)');
});
