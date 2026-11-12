<?php

use App\Modules\Admin\Livewire\Platform\Plans\Edit;
use App\Modules\Admin\Livewire\Platform\Plans\Index;
use App\Modules\Admin\Support\PlatformPlans;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Plan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * "Thêm gói" / "Sửa gói" (design 30): the name, the sale switch and the whole feature table saved
 * at once through PlatformPlans, with one audit line for the name/sale and one for the table. The
 * page says how many customers a save applies to; the list then offers "Hoàn tác", which puts the
 * plan back as it was. Without platform.billing.manage nothing can be read or saved.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    DB::connection('pgsql_admin')->statement('truncate tenant_history restart identity cascade');
    DB::connection('pgsql_admin')->table('tenants')->update(['plan_id' => null]);
    DB::connection('pgsql_admin')->statement('delete from plans');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    app()->setLocale('vi');
    Cache::flush();
});

function peStaff(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

/** @param  array<string, bool|int>  $grants */
function pePlan(string $name, bool $sold = true, array $grants = []): Plan
{
    $staff = peStaff('billing');
    $plan = app(PlatformPlans::class)->create($staff, $name, $sold);
    app(PlatformPlans::class)->setFeatures($staff, $plan, $grants);

    return $plan->fresh();
}

it('refuses a guest, a website member and a support operator', function (): void {
    $plan = pePlan('Cơ bản');
    $host = (string) config('tenancy.platform_host');

    $this->get("http://{$host}/plans/{$plan->key}")->assertRedirect(route('platform.login'));

    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($member, 'admin', ['users.manage']));
    $this->actingAs($member)->get("http://{$host}/plans/new")->assertForbidden();

    $support = peStaff('support');
    $this->actingAs($support)->get("http://{$host}/plans/{$plan->key}")->assertOk()
        ->assertSee(__('admin.plans_noperm_title'))
        ->assertDontSee('Cơ bản');
    foreach (['save', 'toggleSold', 'resetChanges'] as $method) {
        Livewire::actingAs($support)->test(Edit::class, ['key' => $plan->key])->call($method)->assertForbidden();
    }
    Livewire::actingAs($support)->test(Edit::class, ['key' => $plan->key])->call('pick', 'workflow.editorial', 'on')->assertForbidden();
});

it('adds a plan not on sale with its features, and Hoàn tác on the list takes it back', function (): void {
    $staff = peStaff('billing');

    Livewire::actingAs($staff)->test(Edit::class)
        ->set('name', '  Chuyên nghiệp ')
        ->set('features.sites__max', '5')
        ->call('pick', 'workflow.editorial', 'on')
        ->assertSee(__('admin.plans_changes', ['n' => 3]))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('platform.plans.index'));

    $plan = Plan::query()->where('name', 'Chuyên nghiệp')->firstOrFail();
    expect($plan->is_active)->toBeFalse()
        ->and($plan->features()->pluck('value', 'feature_key')->all())->toEqualCanonicalizing(['sites.max' => 5, 'workflow.editorial' => true])
        ->and(AuditLog::query()->where('action', 'tenancy.plan.created')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'tenancy.plan.features_changed')->count())->toBe(1);

    $list = Livewire::actingAs($staff)->test(Index::class)
        ->assertSet('toast', __('admin.plans_toast_added', ['name' => 'Chuyên nghiệp']));
    $list->call('undo');
    expect(Plan::query()->whereKey($plan->id)->exists())->toBeFalse();
});

it('saves an edited table at once for the customers on the plan, says so, and Hoàn tác puts the plan back', function (): void {
    $plan = pePlan('Cơ bản', true, ['sites.max' => 2, 'rbac.custom_roles' => true]);
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['plan_id' => $plan->id]);
    $staff = peStaff('billing');

    Livewire::actingAs($staff)->test(Edit::class, ['key' => $plan->key])
        ->assertSet('features.sites__max', '2')
        ->assertSet('features.rbac__custom_roles', 'on')
        ->assertSee(__('admin.plans_edit_desc_customers', ['n' => 1]))
        ->set('name', 'Cơ bản mới')
        ->set('features.sites__max', '')
        ->set('features.locales__max', '3')
        ->call('pick', 'rbac.custom_roles', 'off')
        ->call('save')
        ->assertRedirect(route('platform.plans.index'));

    $plan->refresh();
    expect($plan->name)->toBe('Cơ bản mới')
        ->and($plan->features()->pluck('value', 'feature_key')->all())->toEqualCanonicalizing(['locales.max' => 3, 'rbac.custom_roles' => false])
        ->and(AuditLog::query()->where('action', 'tenancy.plan.updated')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'tenancy.plan.features_changed')->latest('id')->value('meta'))
        ->toBe(['plan' => $plan->key, 'features' => ['locales.max' => 3, 'rbac.custom_roles' => false]]);

    $handed = session('plans.toast');
    expect($handed['text'])->toBe(__('admin.plans_toast_saved_applied', ['name' => 'Cơ bản mới', 'n' => 1]));

    Livewire::actingAs($staff)->test(Index::class)->call('undo');
    $plan->refresh();
    expect($plan->name)->toBe('Cơ bản')
        ->and($plan->features()->pluck('value', 'feature_key')->all())->toEqualCanonicalizing(['sites.max' => 2, 'rbac.custom_roles' => true]);
});

it('does not write the feature table when only the name changed', function (): void {
    $plan = pePlan('Cơ bản', true, ['sites.max' => 2]);
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');

    Livewire::actingAs(peStaff('billing'))->test(Edit::class, ['key' => $plan->key])
        ->set('name', 'Cơ bản 2')->call('save');

    expect(AuditLog::query()->pluck('action')->all())->toBe(['tenancy.plan.updated']);
});

it('refuses an empty name and a limit under its minimum, saving nothing', function (): void {
    $plan = pePlan('Cơ bản', true, ['sites.max' => 2]);

    Livewire::actingAs(peStaff('billing'))->test(Edit::class, ['key' => $plan->key])
        ->set('name', '   ')
        ->call('save')
        ->assertSet('error', 'name_required')
        ->assertSee(__('admin.plans_err_name_required'))
        ->assertSee(__('admin.plans_name_err'))
        ->set('name', 'Cơ bản')
        ->set('features.sites__max', '0')
        ->call('save')
        ->assertSet('error', 'num')
        ->assertSee(__('admin.plans_err_min', ['min' => 1, 'unit' => 'website']))
        ->assertNoRedirect();

    expect($plan->fresh()->features()->pluck('value', 'feature_key')->all())->toBe(['sites.max' => 2])
        ->and(AuditLog::query()->where('action', 'tenancy.plan.updated')->count())->toBe(0);
});

it('keeps only values it knows from the browser', function (): void {
    Livewire::actingAs(peStaff('billing'))->test(Edit::class)
        ->set('features.sites__max', '12abc')
        ->assertSet('features.sites__max', '12')
        ->set('features.workflow__editorial', 'maybe')
        ->assertSet('features.workflow__editorial', '')
        ->set('features.not__a_feature', '1')
        ->assertSet('features', fn (array $f): bool => ! array_key_exists('not__a_feature', $f))
        ->call('pick', 'sites.max', 'on')
        ->assertSet('features.sites__max', '12');
});

it('shows what customers on the plan will see, the invitation deciding how a locked feature looks', function (): void {
    $plan = pePlan('Cơ bản', true, ['custom_domains.max' => 0]);

    Livewire::actingAs(peStaff('billing'))->test(Edit::class, ['key' => $plan->key])
        ->assertSee(__('admin.plans_f_domains_see_none'))
        ->assertSee(__('admin.plans_f_workflow_off'))
        ->assertSee(__('admin.plans_preview_locked'))
        ->call('pick', 'workflow.editorial', 'on')
        ->assertSee(__('admin.plans_f_workflow_on'))
        ->assertSee(__('admin.plans_price_title'));
});

it('answers an unknown plan with 404', function (): void {
    $host = (string) config('tenancy.platform_host');
    $this->actingAs(peStaff('billing'))->get("http://{$host}/plans/khong-co")->assertNotFound();
});
