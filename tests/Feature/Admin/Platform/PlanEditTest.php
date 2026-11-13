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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * "Thêm gói" / "Sửa gói" (design 30): the name, the sale switch and the whole feature table saved
 * at once through PlatformPlans, with one audit line for the name/sale and one for the table. The
 * page says how many customers a save applies to; the list then offers "Hoàn tác", which puts the
 * plan back as it was while nobody has changed it since. A save over a change made elsewhere is
 * refused. Without platform.billing.manage nothing can be read or saved.
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

it('adds a plan not on sale with its features, and the list says so with no Hoàn tác', function (): void {
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

    // plans are never deleted, so a new plan has nothing to undo: it stops being sold instead
    Livewire::actingAs($staff)->test(Index::class)
        ->assertSet('toast', __('admin.plans_toast_added', ['name' => 'Chuyên nghiệp']))
        ->assertSet('undoAction', null)
        ->call('undo');
    expect(Plan::query()->whereKey($plan->id)->exists())->toBeTrue();
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

it('refuses a save when another staffer changed the plan since the page opened, keeping what was typed', function (string $what): void {
    $plan = pePlan('Cơ bản', true, ['sites.max' => 2]);
    $staff = peStaff('billing');
    $page = Livewire::actingAs($staff)->test(Edit::class, ['key' => $plan->key])
        ->set('name', 'Cơ bản mới')
        ->set('features.locales__max', '4');

    $other = peStaff('billing');
    match ($what) {
        'sale' => app(PlatformPlans::class)->update($other, $plan->fresh(), 'Cơ bản', false),
        'name' => app(PlatformPlans::class)->update($other, $plan->fresh(), 'Cơ bản (cũ)', true),
        'features' => app(PlatformPlans::class)->setFeatures($other, $plan->fresh(), ['sites.max' => 2, 'workflow.editorial' => true]),
    };
    $audits = AuditLog::query()->count();
    $now = ['name' => $plan->fresh()->name, 'sold' => $plan->fresh()->is_active, 'features' => $plan->fresh()->features()->pluck('value', 'feature_key')->all()];

    $page->call('save')
        ->assertNoRedirect()
        ->assertSet('error', 'changed')
        ->assertSee(__('admin.plans_toast_refused'))
        ->assertSet('name', 'Cơ bản mới')
        ->assertSet('features.locales__max', '4');

    // the other staffer's change stands: nothing of this save was written
    expect(['name' => $plan->fresh()->name, 'sold' => $plan->fresh()->is_active, 'features' => $plan->fresh()->features()->pluck('value', 'feature_key')->all()])->toBe($now)
        ->and(AuditLog::query()->count())->toBe($audits)
        ->and(session('plans.toast'))->toBeNull();
})->with(['sale', 'name', 'features']);

it('keeps the page and its refusal when the unsaved-changes dialog saves a refused plan', function (): void {
    $plan = pePlan('Cơ bản', true, ['sites.max' => 2]);

    Livewire::actingAs(peStaff('billing'))->test(Edit::class, ['key' => $plan->key])
        ->set('name', '   ')
        ->call('saveQuietly')
        ->assertStatus(200)
        ->assertSet('error', 'name_required')
        ->assertSee(__('admin.plans_err_name_required'))
        ->assertSee(__('admin.plans_name_err'))
        ->assertSee('data-unsaved-count="1"', false)
        ->set('name', 'Cơ bản')
        ->set('features.sites__max', '0')
        ->call('saveQuietly')
        ->assertStatus(200)
        ->assertSet('error', 'num')
        ->assertSee(__('admin.plans_err_min', ['min' => 1, 'unit' => 'website']));

    expect($plan->fresh()->features()->pluck('value', 'feature_key')->all())->toBe(['sites.max' => 2]);
});

it('saves through the unsaved-changes dialog, leaving nothing counted as unsaved', function (): void {
    $plan = pePlan('Cơ bản', true, ['sites.max' => 2]);

    Livewire::actingAs(peStaff('billing'))->test(Edit::class, ['key' => $plan->key])
        ->set('features.sites__max', '007')
        ->call('saveQuietly')
        ->assertSet('error', '')
        ->assertSet('features.sites__max', '7')
        ->assertSee('data-unsaved-count="0"', false);

    expect($plan->fresh()->features()->pluck('value', 'feature_key')->all())->toBe(['sites.max' => 7]);
});

it('Hoàn tác puts nothing back when part of the plan cannot be put back', function (): void {
    $plan = pePlan('Cơ bản', true, ['sites.max' => 2]);
    // a feature the plan granted that has since left the system: putting the old table back is refused
    DB::connection('pgsql_admin')->table('plan_features')->insert(['plan_id' => $plan->id, 'feature_key' => 'gone.feature', 'value' => 'true']);
    $staff = peStaff('billing');

    Livewire::actingAs($staff)->test(Edit::class, ['key' => $plan->key])
        ->set('name', 'Cơ bản mới')->set('sold', false)->call('save')->assertRedirect(route('platform.plans.index'));
    $audits = AuditLog::query()->count();

    Livewire::actingAs($staff)->test(Index::class)
        ->call('undo')
        ->assertSet('toast', __('admin.plans_toast_undo_failed'))
        ->assertSet('toastTone', 'error');

    // the name and sale went back inside the same transaction as the table, so neither stayed
    $plan->refresh();
    expect($plan->name)->toBe('Cơ bản mới')
        ->and($plan->is_active)->toBeFalse()
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('Hoàn tác refuses to put a plan back over a change made since the save', function (): void {
    $plan = pePlan('Cơ bản', true, ['sites.max' => 2]);
    $staff = peStaff('billing');

    Livewire::actingAs($staff)->test(Edit::class, ['key' => $plan->key])
        ->set('name', 'Cơ bản mới')->set('features.sites__max', '3')->call('save');
    $list = Livewire::actingAs($staff)->test(Index::class);

    app(PlatformPlans::class)->setFeatures(peStaff('billing'), $plan->fresh(), ['sites.max' => 9]);
    $list->call('undo')->assertSet('toast', __('admin.plans_toast_undo_failed'));

    $plan->refresh();
    expect($plan->name)->toBe('Cơ bản mới')
        ->and($plan->features()->pluck('value', 'feature_key')->all())->toBe(['sites.max' => 9]);
});

it('never gives a plan a key the console uses or a key longer than its address takes', function (string $name, string $expected): void {
    $staff = peStaff('billing');
    $host = (string) config('tenancy.platform_host');
    pePlan('Đã có '.$name); // a neighbour, so the lookups are not alone

    Livewire::actingAs($staff)->test(Edit::class)->set('name', $name)->call('save')->assertHasNoErrors();
    $plan = Plan::query()->where('name', mb_substr(trim($name), 0, Edit::NAME_MAX))->latest('id')->firstOrFail();

    expect($plan->key)->not->toBe('new')
        ->and(strlen($plan->key))->toBeLessThanOrEqual(64)
        ->and($plan->key)->toMatch($expected);
    $this->actingAs($staff)->get("http://{$host}/plans/{$plan->key}")->assertOk()->assertSee($plan->name);
})->with([
    'named New' => ['New', '/^new-[a-z0-9]{4}$/'],
    '70 Latin characters, cut to 60' => [str_repeat('Gói doanh nghiệp ', 4).'AB', '/^[a-z0-9-]{1,59}$/'],
    '63 Chinese characters, cut to 60' => [str_repeat('企业套餐专业版', 9), '/^[a-z0-9-]{1,59}$/'],
]);

it('caps the key of a long name, and of a taken one with its suffix, at 64 characters', function (): void {
    $staff = peStaff('billing');
    $long = str_repeat('Doanh nghiệp lớn ', 5); // 85 characters: longer than the editor allows, as an import could send

    $first = app(PlatformPlans::class)->create($staff, $long);
    $second = app(PlatformPlans::class)->create($staff, $long);
    $cjk = app(PlatformPlans::class)->create($staff, str_repeat('企业套餐专业版', 10));

    expect(strlen($first->key))->toBeLessThanOrEqual(59)
        ->and(strlen($second->key))->toBeLessThanOrEqual(64)
        ->and($second->key)->toStartWith($first->key.'-')
        ->and(strlen($cjk->key))->toBeLessThanOrEqual(59)
        ->and($cjk->key)->toMatch('/^[a-z0-9-]+$/');
});

it('counts the customers on the plan when the page opens, not on each number typed', function (): void {
    $plan = pePlan('Cơ bản', true, ['sites.max' => 2]);
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['plan_id' => $plan->id]);
    $page = Livewire::actingAs(peStaff('billing'))->test(Edit::class, ['key' => $plan->key])
        ->assertSee(__('admin.plans_edit_desc_customers', ['n' => 1]));

    $tenantReads = 0;
    DB::listen(function (QueryExecuted $q) use (&$tenantReads): void {
        $tenantReads += (int) str_contains($q->sql, 'from "tenants"');
    });
    $page->set('features.sites__max', '3')->set('features.sites__max', '4')
        ->assertSee(__('admin.plans_edit_desc_customers', ['n' => 1]));

    expect($tenantReads)->toBe(0);
});

it('opens a plan in the editor on a fixed query budget, whatever the number of plans and customers', function (): void {
    $host = (string) config('tenancy.platform_host');
    $top = peStaff('super_admin');
    $plan = pePlan('Cơ bản', true, ['sites.max' => 2, 'workflow.editorial' => true]);
    $n = 0;
    $counting = false;
    DB::listen(function (QueryExecuted $q) use (&$n, &$counting): void {
        $n += (int) $counting;
    });
    $count = function () use ($host, $top, $plan, &$n, &$counting): int {
        app(PlatformAccess::class)->forgetMemo(); // each request starts with the role checks unanswered
        $n = 0;
        $counting = true;
        $this->actingAs($top)->get("http://{$host}/plans/{$plan->key}")->assertOk();
        $counting = false;

        return $n;
    };

    $count(); // the first request also warms the domain and role lookups
    $few = $count();
    foreach (range(2, 12) as $i) {
        pePlan('Gói '.$i, $i % 2 === 0, ['sites.max' => $i]);
    }
    DB::connection('pgsql_admin')->table('tenants')->update(['plan_id' => $plan->id]);

    expect($count())->toBe($few)
        ->and($few)->toBeLessThanOrEqual(7); // measured at 7 when the budget was set
});
