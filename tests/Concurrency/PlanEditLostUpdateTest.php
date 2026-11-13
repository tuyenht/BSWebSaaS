<?php

use App\Modules\Admin\Livewire\Platform\Plans\Edit;
use App\Modules\Admin\Support\PlatformPlans;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Models\Plan;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Two staffers on one plan at the same moment: one renames it in the editor while the other takes
 * it off sale. The editor's save must never put the plan back on sale (a lost update): it locks
 * the plan row, compares it with what the page read, and refuses when it differs.
 *
 * A real race on two connections, made deterministic without timing (CustomerPlanChangeRaceTest):
 * the rival change runs from inside the save, on pgsql_admin with a short lock_timeout, either
 * right after the save has looked the plan up (before its lock) or right after it has locked the
 * row. Before the lock, the rival commits and the save must refuse; under the lock, the rival
 * cannot take the row, gives up, and runs again once the save has committed.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    DB::connection('pgsql_admin')->statement('truncate tenant_history restart identity cascade');
    DB::connection('pgsql_admin')->table('tenants')->update(['plan_id' => null]);
    DB::connection('pgsql_admin')->statement('delete from plans');
    test()->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    Cache::flush();
});

function pcStaff(): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('billing'));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

/** Takes the plan off sale on pgsql_admin, as another staffer's request would. False when the row stayed locked. */
function pcRivalOffSale(int $planId): bool
{
    $admin = DB::connection('pgsql_admin');

    try {
        $admin->transaction(function () use ($admin, $planId): void {
            $admin->statement("SET LOCAL lock_timeout = '500ms'");
            $admin->update('update plans set is_active = false where id = ?', [$planId]);
        });
    } catch (QueryException $e) {
        if (($e->errorInfo[0] ?? null) === '55P03') {   // lock_not_available
            return false;
        }

        throw $e;
    }

    return true;
}

it('never puts a plan back on sale that another staffer took off while the editor was saving', function (string $when): void {
    $staff = pcStaff();
    $plan = app(PlatformPlans::class)->create($staff, 'Cơ bản', true);
    $page = Livewire::actingAs($staff)->test(Edit::class, ['key' => $plan->key])->set('name', 'Cơ bản mới');

    $rivalFirst = null;
    DB::listen(function (QueryExecuted $query) use (&$rivalFirst, $plan, $when): void {
        $sql = strtolower($query->sql);
        if ($rivalFirst !== null || $query->connectionName === 'pgsql_admin' || ! str_contains($sql, 'from "plans"')) {
            return;
        }
        if (($when === 'locked') !== str_contains($sql, 'for update')) {
            return;
        }
        $rivalFirst = false;   // set before running it, so the rival's own queries never re-enter
        $rivalFirst = pcRivalOffSale($plan->id);
    });

    $page->call('save');

    expect($rivalFirst)->not->toBeNull();   // the rival did race the save
    if ($rivalFirst === false) {
        expect(pcRivalOffSale($plan->id))->toBeTrue();
    }

    $plan->refresh();
    expect($plan->is_active)->toBeFalse();   // the rival's change is never lost
    if ($when === 'before_lock') {
        // the rival committed first: the save saw the change and wrote nothing
        $page->assertSet('error', 'changed')->assertSet('name', 'Cơ bản mới');
        expect($plan->name)->toBe('Cơ bản');
    } else {
        // the save held the row: it committed first, the rival went after it
        expect($rivalFirst)->toBeFalse()
            ->and($plan->name)->toBe('Cơ bản mới');
    }
})->with(['before_lock', 'locked']);
