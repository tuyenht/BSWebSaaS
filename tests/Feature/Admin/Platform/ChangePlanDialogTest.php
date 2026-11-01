<?php

use App\Modules\Admin\Livewire\Platform\Tenants\ChangePlanDialog;
use App\Modules\Admin\Support\PlatformPlans;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * "Đổi gói" (design 03): staff with platform.billing.manage move a customer to another plan on
 * sale, giving a reason that goes on record with the old and the new plan. Before saving, the
 * dialog says what changes and where the customer already uses more than the new plan gives.
 * Without the permission every action is refused and nothing changes.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // customer T1 (id 1) with websites A and B
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    DB::connection('pgsql_admin')->statement('delete from plans');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    app()->setLocale('vi');
    Cache::flush();
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['ulid' => (string) Str::ulid(), 'name' => 'Cơ khí An Vinh']);
});

function cpStaff(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

function cpCustomer(): Tenant
{
    return Tenant::query()->findOrFail(1);
}

/**
 * @param  array<string, bool|int>  $grants
 */
function cpPlan(User $staff, string $name, array $grants = []): Plan
{
    $plan = app(PlatformPlans::class)->create($staff, $name);
    if ($grants !== []) {
        app(PlatformPlans::class)->setFeatures($staff, $plan, $grants);
    }

    return $plan->fresh();
}

it('moves a customer to the chosen plan, recording the reason and both plans', function (): void {
    $staff = cpStaff('billing');
    $basic = cpPlan($staff, 'Cơ bản', ['sites.max' => 2]);
    $pro = cpPlan($staff, 'Chuyên nghiệp', ['sites.max' => 5, 'locales.max' => 3]);
    app(PlatformPlans::class)->changeCustomerPlan($staff, cpCustomer(), $basic, 'Bắt đầu');
    $customer = cpCustomer();

    Livewire::actingAs($staff)->test(ChangePlanDialog::class)
        ->call('openFor', $customer->ulid)
        ->assertSet('open', true)
        ->assertSee(__('admin.customers_dlg_title', ['name' => 'Cơ khí An Vinh']))
        ->assertSee(__('admin.customers_dlg_current_tag'))
        ->call('choose', $pro->key)
        ->assertSet('to', $pro->key)
        ->assertSee('Số ngôn ngữ tối đa')
        ->assertSee(__('admin.customers_dlg_change_up'))
        ->assertSee(__('admin.customers_dlg_apply', ['n' => 2, 'list' => ': A · B']))
        ->set('reason', 'Khách gia hạn năm')
        ->call('save')
        ->assertSet('open', false)
        ->assertSet('toast', __('admin.customers_changed_toast', ['plan' => 'Chuyên nghiệp', 'name' => 'Cơ khí An Vinh', 'n' => 2]))
        ->assertDispatched('customer-plan-changed');

    $row = AuditLog::query()->where('action', 'tenancy.customer.plan_changed')->orderByDesc('id')->firstOrFail();
    expect(cpCustomer()->plan_id)->toBe($pro->id)
        ->and($row->actor_id)->toBe($staff->id)
        ->and($row->meta)->toMatchArray(['customer' => $customer->ulid, 'from' => $basic->key, 'to' => $pro->key, 'reason' => 'Khách gia hạn năm']);
});

it('asks for a plan and a reason before saving, and changes nothing until then', function (): void {
    $staff = cpStaff('billing');
    $pro = cpPlan($staff, 'Chuyên nghiệp');
    $customer = cpCustomer();

    $dialog = Livewire::actingAs($staff)->test(ChangePlanDialog::class)->call('openFor', $customer->ulid)
        ->call('save')
        ->assertSet('tried', true)->assertSet('open', true)
        ->assertSee(__('admin.customers_dlg_plan_required'));
    $dialog->call('choose', $pro->key)->set('reason', '   ')->call('save')
        ->assertSet('open', true)
        ->assertSee(__('admin.customers_dlg_reason_error'));

    expect(cpCustomer()->plan_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'tenancy.customer.plan_changed')->count())->toBe(0);
});

it('offers only plans on sale other than the current one', function (): void {
    $staff = cpStaff('billing');
    $pro = cpPlan($staff, 'Chuyên nghiệp');
    $old = app(PlatformPlans::class)->update($staff, cpPlan($staff, 'Cũ'), 'Cũ', false);
    app(PlatformPlans::class)->changeCustomerPlan($staff, cpCustomer(), $pro, 'Bắt đầu');

    Livewire::actingAs($staff)->test(ChangePlanDialog::class)->call('openFor', cpCustomer()->ulid)
        ->call('choose', $pro->key)->assertSet('to', '')
        ->call('choose', $old->key)->assertSet('to', '')
        ->call('choose', 'nope')->assertSet('to', '')
        ->assertDontSee('>Cũ<', false);
});

it('says when the chosen plan stopped being sold meanwhile, keeping the reason', function (): void {
    $staff = cpStaff('billing');
    $pro = cpPlan($staff, 'Chuyên nghiệp');

    $dialog = Livewire::actingAs($staff)->test(ChangePlanDialog::class)->call('openFor', cpCustomer()->ulid)
        ->call('choose', $pro->key)->set('reason', 'Nâng cấp');
    app(PlatformPlans::class)->update($staff, $pro, 'Chuyên nghiệp', false);

    $dialog->call('save')
        ->assertSet('failure', 'not_sold')->assertSet('to', '')->assertSet('reason', 'Nâng cấp')->assertSet('open', true)
        ->assertSee(__('admin.customers_dlg_not_sold'));
    expect(cpCustomer()->plan_id)->toBeNull();
});

it('warns where the customer already uses more than the new plan gives', function (): void {
    $staff = cpStaff('billing');
    $pro = cpPlan($staff, 'Chuyên nghiệp', ['sites.max' => 5, 'custom_domains.max' => 3]);
    $small = cpPlan($staff, 'Nhỏ', ['sites.max' => 1, 'custom_domains.max' => 1]);
    app(PlatformPlans::class)->changeCustomerPlan($staff, cpCustomer(), $pro, 'Bắt đầu');
    // website A has two own domains (a name and its www. spelling count once) and one more
    DB::connection('pgsql_admin')->table('domains')->insert([
        ['site_id' => 1, 'hostname' => 'anvinh.vn', 'kind' => 'custom', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 1, 'hostname' => 'www.anvinh.vn', 'kind' => 'custom', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 1, 'hostname' => 'anvinh.com', 'kind' => 'custom', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);

    Livewire::actingAs($staff)->test(ChangePlanDialog::class)->call('openFor', cpCustomer()->ulid)
        ->call('choose', $small->key)
        ->assertSee(__('admin.customers_dlg_over_title'))
        ->assertSee(__('admin.customers_dlg_over_sites', ['n' => 2, 'max' => 1]))
        ->assertSee(__('admin.customers_dlg_over_limit', ['site' => 'A', 'used' => __('admin.customers_usage_domains', ['n' => 2]), 'max' => '1']))
        ->assertDontSee(__('admin.customers_dlg_over_limit', ['site' => 'B', 'used' => __('admin.customers_usage_domains', ['n' => 0]), 'max' => '1']));
});

it('refuses every plan action to staff without platform.billing.manage', function (): void {
    $billing = cpStaff('billing');
    $pro = cpPlan($billing, 'Chuyên nghiệp');
    $support = cpStaff('support');

    Livewire::actingAs($support)->test(ChangePlanDialog::class)->call('openFor', cpCustomer()->ulid)->assertForbidden();
    Livewire::actingAs($support)->test(ChangePlanDialog::class)->call('choose', $pro->key)->assertForbidden();
    Livewire::actingAs($support)->test(ChangePlanDialog::class)->set('reason', 'x')->call('save')->assertForbidden();

    $tenantUser = User::factory()->create();
    Livewire::actingAs($tenantUser)->test(ChangePlanDialog::class)->assertForbidden();

    expect(cpCustomer()->plan_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'tenancy.customer.plan_changed')->count())->toBe(0);
});

it('takes the customer only by a real ulid, and keeps it locked', function (): void {
    $staff = cpStaff('billing');

    Livewire::actingAs($staff)->test(ChangePlanDialog::class)
        ->call('openFor', '1')->assertSet('open', false)->assertSet('customer', '')
        ->call('openFor', (string) Str::ulid())->assertSet('open', false);

    expect(fn () => Livewire::actingAs($staff)->test(ChangePlanDialog::class)->set('customer', cpCustomer()->ulid))
        ->toThrow(Exception::class);
});
