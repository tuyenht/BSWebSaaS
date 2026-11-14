<?php

use App\Modules\Admin\Livewire\Platform\Plans\Index;
use App\Modules\Admin\Support\Plans\PlanCatalog;
use App\Modules\Admin\Support\PlatformPlans;
use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * "Gói & tính năng" (design 30), the plan list: staff with platform.billing.manage switch a plan's
 * sale, reorder plans, duplicate one (and take the copy back with "Hoàn tác" while nobody has had it), and switch the
 * upgrade invitation for every website. Each change leaves an audit line; without the permission
 * every action is refused and nothing changes. Platform admins also see the website types, read only.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // customer T1 (id 1) with websites A and B
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    DB::connection('pgsql_admin')->statement('truncate tenant_history restart identity cascade');
    DB::connection('pgsql_admin')->table('tenants')->update(['plan_id' => null]);
    DB::connection('pgsql_admin')->statement('delete from plans');
    DB::connection('pgsql_admin')->table('platform_settings')->where('group_name', 'plans')->delete();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    app()->setLocale('vi');
    Cache::flush();
});

function plStaff(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

/** @param  array<string, bool|int>  $grants */
function plPlan(string $name, bool $sold = true, array $grants = []): Plan
{
    $staff = plStaff('billing');
    $plan = app(PlatformPlans::class)->create($staff, $name, $sold);
    if ($grants !== []) {
        app(PlatformPlans::class)->setFeatures($staff, $plan, $grants);
    }

    return $plan->fresh();
}

function plAudits(string $action): int
{
    return AuditLog::query()->where('action', $action)->count();
}

it('sends a guest to the platform login and refuses a website member', function (): void {
    $host = (string) config('tenancy.platform_host');
    $this->get("http://{$host}/plans")->assertRedirect(route('platform.login'));

    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($member, 'admin', ['users.manage', 'entries.view']));
    $this->actingAs($member)->get("http://{$host}/plans")->assertForbidden();
});

it('tells a support operator their group cannot open the page, and refuses every action', function (): void {
    $plan = plPlan('Cơ bản');
    $support = plStaff('support');
    $host = (string) config('tenancy.platform_host');

    $this->actingAs($support)->get("http://{$host}/plans")->assertOk()
        ->assertSee(__('admin.plans_noperm_title'))
        ->assertDontSee(__('admin.plans_inv_title'))
        ->assertDontSee(__('admin.plans_types_title'));

    foreach ([['toggleSold', [$plan->key]], ['move', [$plan->key, 1]], ['duplicate', [$plan->key]], ['toggleInvitation', []]] as [$method, $args]) {
        Livewire::actingAs($support)->test(Index::class)->call($method, ...$args)->assertForbidden();
    }

    expect($plan->fresh()->is_active)->toBeTrue()
        ->and(Plan::query()->count())->toBe(1)
        ->and(app(UpgradeInvitation::class)->isOn())->toBeFalse();
});

it('shows billing the plans with their customers and main features, but not the website types', function (): void {
    $basic = plPlan('Cơ bản', true, ['sites.max' => 2, 'workflow.editorial' => true]);
    plPlan('Chuyên nghiệp', false);
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['plan_id' => $basic->id]);

    Livewire::actingAs(plStaff('billing'))->test(Index::class)
        ->assertSee(__('admin.plans_role_strip_billing'))
        ->assertSee('Cơ bản')
        ->assertSee('Chuyên nghiệp')
        ->assertSee(__('admin.plans_customers_n', ['n' => 1]))
        ->assertSee(__('admin.plans_customers_none'))
        ->assertSee('Duyệt nhiều cấp · 2 website')
        ->assertSee(__('admin.plans_list_count', ['n' => 2, 'sold' => 1]))
        ->assertDontSee('id="loai-website"', false);
});

it('shows a platform admin the website types, read only, with their websites, modules and role templates', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->table('site_types')->insertOrIgnore(['key' => 'corporate', 'label' => json_encode(['vi' => 'Website doanh nghiệp', 'en' => 'Corporate website']), 'meta' => '{}']);
    $admin->table('sites')->update(['site_type_id' => $admin->table('site_types')->where('key', 'corporate')->value('id')]);

    Livewire::actingAs(plStaff('super_admin'))->test(Index::class)
        ->assertSee(__('admin.plans_types_title'))
        ->assertSee(__('admin.plans_types_read_only'))
        ->assertSee(__('admin.plans_types_sites', ['n' => 2]))
        ->assertSee(__('rbac.role.owner'))
        ->assertSee(__('rbac.role.editor'))
        ->assertDontSee(__('admin.plans_role_strip_billing'));
});

it('filters by name, ignoring accents, and by sale status', function (): void {
    plPlan('Cơ bản');
    plPlan('Doanh nghiệp lớn', false);

    // the search box's own example names a plan, so these look for names no example uses
    Livewire::actingAs(plStaff('billing'))->test(Index::class)
        ->set('search', 'doanh nghiep')
        ->assertSee('Doanh nghiệp lớn')->assertDontSee('Cơ bản')
        ->assertSee(__('admin.plans_matched', ['n' => 1, 'm' => 2]))
        ->call('clearFilters')
        ->set('filter', 'sold')
        ->assertSee('Cơ bản')->assertDontSee('Doanh nghiệp lớn')
        ->set('filter', 'nonsense')
        ->assertSet('filter', 'all');
});

it('takes a plan off sale with an audit line, keeps its customers and stops it being given out; Hoàn tác puts it back', function (): void {
    $plan = plPlan('Cơ bản');
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['plan_id' => $plan->id]);
    $staff = plStaff('billing');

    $page = Livewire::actingAs($staff)->test(Index::class)
        ->call('toggleSold', $plan->key)
        ->assertSet('toast', __('admin.plans_toast_sold_off_kept', ['name' => 'Cơ bản', 'n' => 1]));

    expect($plan->fresh()->is_active)->toBeFalse()
        ->and(Tenant::query()->findOrFail(1)->plan_id)->toBe($plan->id)
        ->and(plAudits('tenancy.plan.updated'))->toBe(1);

    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['plan_id' => null]);
    $other = Tenant::query()->findOrFail(1);
    expect(fn () => app(PlatformPlans::class)->changeCustomerPlan($staff, $other, $plan->fresh(), 'thử'))
        ->toThrow(PlanChangeRejectedException::class, 'plan_not_sold');

    $page->call('undo')->assertSet('toast', '');
    expect($plan->fresh()->is_active)->toBeTrue();
});

it('tells "no longer sold" from "not sold yet"', function (): void {
    $once = plPlan('Đã từng bán');
    plPlan('Mới', false);
    app(PlatformPlans::class)->update(plStaff('billing'), $once, $once->name, false);

    Livewire::actingAs(plStaff('billing'))->test(Index::class)
        ->assertSeeInOrder(['Đã từng bán', __('admin.plans_status_off'), 'Mới', __('admin.plans_status_new')]);
});

it('moves a plan up and down with an audit line, refuses past the ends, and Hoàn tác restores the order', function (): void {
    $a = plPlan('A');
    $b = plPlan('B');
    $c = plPlan('C');
    $order = fn (): array => Plan::query()->orderBy('sort_order')->pluck('key')->all();

    $page = Livewire::actingAs(plStaff('billing'))->test(Index::class)
        ->call('move', $c->key, -1)
        ->assertSet('toast', __('admin.plans_toast_moved_up', ['name' => 'C', 'pos' => 2]));
    expect($order())->toBe([$a->key, $c->key, $b->key])
        ->and(plAudits('tenancy.plan.reordered'))->toBe(1);

    $page->call('undo');
    expect($order())->toBe([$a->key, $b->key, $c->key]);

    $page->call('move', $a->key, -1)->call('move', $c->key, 1)->call('move', $b->key, 7);
    expect($order())->toBe([$a->key, $b->key, $c->key])
        ->and(plAudits('tenancy.plan.reordered'))->toBe(2);
});

it('duplicates a plan as a copy not on sale, and Hoàn tác discards the copy with an audit line', function (): void {
    $plan = plPlan('Cơ bản', true, ['sites.max' => 3]);

    $page = Livewire::actingAs(plStaff('billing'))->test(Index::class)
        ->call('duplicate', $plan->key)
        ->assertSet('toast', __('admin.plans_toast_duplicated', ['name' => 'Bản sao của Cơ bản']))
        ->assertSet('toastTone', 'success');

    $copy = Plan::query()->where('name', 'Bản sao của Cơ bản')->firstOrFail();
    $page->assertSet('undoAction', ['kind' => 'discard', 'plan' => $copy->key, 'after' => ['name' => 'Bản sao của Cơ bản', 'features' => ['sites.max' => 3]]]);
    expect($copy->is_active)->toBeFalse()
        ->and($copy->features()->pluck('value', 'feature_key')->all())->toBe(['sites.max' => 3])
        ->and(plAudits('tenancy.plan.duplicated'))->toBe(1);

    $page->call('undo')->assertSet('toast', '')->assertSet('undoAction', null);
    expect(Plan::query()->whereKey($copy->id)->exists())->toBeFalse()
        ->and(Plan::query()->whereKey($plan->id)->exists())->toBeTrue()
        ->and(plAudits('tenancy.plan.discarded'))->toBe(1)
        ->and(AuditLog::query()->where('action', 'tenancy.plan.discarded')->value('meta'))->toMatchArray(['plan' => $copy->key]);
});

it('never discards a plan that is or was on sale, that a customer (a removed one too) is on, or that the plan history names', function (): void {
    $staff = plStaff('billing');
    $admin = DB::connection('pgsql_admin');
    $sold = plPlan('Đang bán');
    $once = plPlan('Từng bán');
    app(PlatformPlans::class)->update($staff, $once, $once->name, false);
    $held = plPlan('Có khách', false);
    $admin->table('tenants')->where('id', 1)->update(['plan_id' => $held->id]);
    $removed = plPlan('Khách đã xóa', false);
    $gone = $admin->table('tenants')->insertGetId([
        'ulid' => (string) Str::ulid(), 'name' => 'Đã xóa', 'plan_id' => $removed->id, 'deleted_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $from = plPlan('Lịch sử từ', false);
    $to = plPlan('Lịch sử tới', false);
    $admin->table('tenant_history')->insert([
        ['tenant_id' => $gone, 'kind' => 'plan_changed', 'from_plan_id' => $from->id, 'to_plan_id' => null],
        ['tenant_id' => $gone, 'kind' => 'plan_changed', 'from_plan_id' => null, 'to_plan_id' => $to->id],
    ]);

    foreach ([$sold, $once, $held, $removed, $from, $to] as $plan) {
        expect(fn () => app(PlatformPlans::class)->discardCopy($staff, $plan->fresh(), ['name' => $plan->name, 'features' => PlanCatalog::grantsOf($plan)]))
            ->toThrow(PlanChangeRejectedException::class, 'plan_in_use');
    }
    expect(Plan::query()->count())->toBe(6)
        ->and(plAudits('tenancy.plan.discarded'))->toBe(0);

    // a copy someone was put on before "Hoàn tác": the screen says so in plain words and keeps it
    $page = Livewire::actingAs($staff)->test(Index::class)->call('duplicate', $sold->key);
    $copy = Plan::query()->latest('id')->firstOrFail();
    $admin->table('tenants')->where('id', 1)->update(['plan_id' => $copy->id]);
    $page->call('undo')
        ->assertSet('toast', __('admin.plans_toast_undo_failed'))
        ->assertSet('toastTone', 'error');
    expect(Plan::query()->whereKey($copy->id)->exists())->toBeTrue()
        ->and(plAudits('tenancy.plan.discarded'))->toBe(0);
});

it('keeps a copy someone renamed or gave other features to before Hoàn tác', function (): void {
    $plan = plPlan('Cơ bản', true, ['sites.max' => 3]);
    $other = plStaff('billing');

    // renamed in another tab while the toast is still open
    $page = Livewire::actingAs(plStaff('billing'))->test(Index::class)->call('duplicate', $plan->key);
    $copy = Plan::query()->latest('id')->firstOrFail();
    app(PlatformPlans::class)->update($other, $copy, 'Gói riêng', false);
    $page->call('undo')
        ->assertSet('toast', __('admin.plans_toast_undo_failed'))
        ->assertSet('toastTone', 'error');
    expect(Plan::query()->whereKey($copy->id)->value('name'))->toBe('Gói riêng');

    // feature table changed meanwhile
    $page = Livewire::actingAs(plStaff('billing'))->test(Index::class)->call('duplicate', $plan->key);
    $second = Plan::query()->latest('id')->firstOrFail();
    app(PlatformPlans::class)->setFeatures($other, $second, ['sites.max' => 5]);
    $page->call('undo')->assertSet('toast', __('admin.plans_toast_undo_failed'));
    expect(Plan::query()->whereKey($second->id)->exists())->toBeTrue();

    // an undo that does not say how the copy was left deletes nothing
    expect(fn () => app(PlatformPlans::class)->discardCopy($other, $second->fresh(), []))
        ->toThrow(PlanChangeRejectedException::class, 'plan_changed');
    expect(plAudits('tenancy.plan.discarded'))->toBe(0);
});

it('refuses the discard to staff without platform.billing.manage', function (): void {
    $free = plPlan('Trống', false);

    expect(fn () => app(PlatformPlans::class)->discardCopy(plStaff('support'), $free, ['name' => $free->name, 'features' => []]))
        ->toThrow(AuthorizationException::class);
    expect(Plan::query()->whereKey($free->id)->exists())->toBeTrue()
        ->and(plAudits('tenancy.plan.discarded'))->toBe(0);
});

it('switches the upgrade invitation with an audit line, warns while the plans do not differ, and Hoàn tác switches it back', function (): void {
    plPlan('Cơ bản', true, ['sites.max' => 1]);
    plPlan('Nâng cao', true, ['sites.max' => 1]);

    $page = Livewire::actingAs(plStaff('billing'))->test(Index::class)
        ->assertDontSee(__('admin.plans_inv_same'))
        ->call('toggleInvitation')
        ->assertSet('toast', __('admin.plans_toast_inv_on_same'))
        ->assertSee(__('admin.plans_inv_same'));

    expect(app(UpgradeInvitation::class)->isOn())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'tenancy.plan.upgrade_invitation_changed')->value('meta'))->toBe(['on' => true]);

    $page->call('undo');
    expect(app(UpgradeInvitation::class)->isOn())->toBeFalse()
        ->and(plAudits('tenancy.plan.upgrade_invitation_changed'))->toBe(2);
});

it('answers an unknown plan key with 404', function (): void {
    Livewire::actingAs(plStaff('billing'))->test(Index::class)->call('toggleSold', 'no-such-plan')->assertNotFound();
});

it('reads the list in the same number of queries whatever the number of plans', function (): void {
    $host = (string) config('tenancy.platform_host');
    $top = plStaff('super_admin');
    $n = 0;
    $counting = false;
    DB::listen(function (QueryExecuted $q) use (&$n, &$counting): void {
        $n += (int) $counting;
    });
    $count = function () use ($host, $top, &$n, &$counting): int {
        app(PlatformAccess::class)->forgetMemo(); // each request starts with the role checks unanswered
        $n = 0;
        $counting = true;
        $this->actingAs($top)->get("http://{$host}/plans")->assertOk();
        $counting = false;

        return $n;
    };

    plPlan('Một');
    $count(); // the first request also warms the domain and role lookups
    $few = $count();
    foreach (range(2, 12) as $i) {
        plPlan('Gói '.$i, $i % 2 === 0, ['sites.max' => $i]);
    }

    expect($count())->toBe($few);
});

it('counts customers and websites once, not again on a search, a filter or a page turn', function (): void {
    $basic = plPlan('Cơ bản', true, ['sites.max' => 2]);
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['plan_id' => $basic->id]);
    $page = Livewire::actingAs(plStaff('super_admin'))->test(Index::class)
        ->assertSee(__('admin.plans_customers_n', ['n' => 1]));

    $counts = 0;
    DB::listen(function (QueryExecuted $q) use (&$counts): void {
        // the grouped counts of the list only (a write also counts one plan's customers on its own)
        $counts += (int) (str_contains($q->sql, 'group by') && (str_contains($q->sql, '"tenants"') || str_contains($q->sql, '"sites"')));
    });
    $page->set('search', 'co ban')->set('filter', 'sold')->set('per', 20)->call('clearFilters')
        ->assertSee(__('admin.plans_customers_n', ['n' => 1]));
    expect($counts)->toBe(0);

    // a write counts again on the next render
    $page->call('toggleSold', $basic->key)->assertSee(__('admin.plans_customers_n', ['n' => 1]));
    expect($counts)->toBeGreaterThan(0);
});

it('refuses, without an error page, a copy whose key another staffer took at that same moment', function (): void {
    $basic = plPlan('Cơ bản');
    $raced = false;
    Plan::creating(function (Plan $plan) use (&$raced): void {
        if (! $raced) {
            $raced = true;
            Plan::query()->insert(['key' => $plan->key, 'name' => 'Gói của người khác', 'is_active' => false, 'sort_order' => 999]);
        }
    });

    Livewire::actingAs(plStaff('super_admin'))->test(Index::class)
        ->call('duplicate', $basic->key)
        ->assertStatus(200)
        ->assertSee(__('admin.plans_toast_refused'));

    expect($raced)->toBeTrue()
        ->and(Plan::query()->where('name', 'Gói của người khác')->exists())->toBeFalse();
});
