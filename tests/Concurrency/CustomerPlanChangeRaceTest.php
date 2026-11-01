<?php

use App\Modules\Tenancy\Actions\ChangeCustomerPlan;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Two changes of one customer's plan at the same moment must each record, as "from", the plan the
 * other left, or the customer's history (tenant_history) tells a story the plan never went
 * through. ChangeCustomerPlan locks the customer row before it reads the plan; without the lock it
 * reads a plan that a rival change is replacing, and records it as "from" once the rival commits.
 *
 * This is a real race on two connections, made deterministic without timing: the rival change
 * runs from inside the action, right after the action has read the customer's row, on another
 * connection (pgsql_admin) with a short lock_timeout. With the lock, the rival cannot take the
 * row, gives up, and runs again once the action has committed; without it, the rival commits
 * under the action's feet.
 */

beforeEach(function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate tenant_history restart identity');
    seedTenancy();   // tenant 1, no plan
    $admin->statement('delete from plans');
});

/**
 * Moves the customer to $to on the pgsql_admin connection, as another request would: lock the
 * row, read its plan, change it and record the change. False when the row stayed locked.
 */
function prRivalChange(int $tenantId, int $to): bool
{
    $admin = DB::connection('pgsql_admin');

    try {
        $admin->transaction(function () use ($admin, $tenantId, $to): void {
            $admin->statement("SET LOCAL lock_timeout = '500ms'");
            $was = $admin->scalar('select plan_id from tenants where id = ? for update', [$tenantId]);
            $admin->update('update tenants set plan_id = ? where id = ?', [$to, $tenantId]);
            $admin->insert(
                "insert into tenant_history (tenant_id, kind, from_plan_id, to_plan_id) values (?, 'plan_changed', ?, ?)",
                [$tenantId, $was, $to],
            );
        });
    } catch (QueryException $e) {
        if (($e->errorInfo[0] ?? null) === '55P03') {   // lock_not_available
            return false;
        }

        throw $e;
    }

    return true;
}

it('records, for each of two plan changes at once, the plan the other one left', function (): void {
    $start = Plan::query()->create(['key' => 'start', 'name' => 'Start', 'is_active' => true]);
    $mine = Plan::query()->create(['key' => 'mine', 'name' => 'Mine', 'is_active' => true]);
    $rival = Plan::query()->create(['key' => 'rival', 'name' => 'Rival', 'is_active' => true]);
    DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->update(['plan_id' => $start->id]);
    $customer = Tenant::query()->findOrFail(1);   // loaded before the rival starts listening

    $rivalFirst = null;
    DB::listen(function (QueryExecuted $query) use (&$rivalFirst, $rival): void {
        if ($rivalFirst !== null || $query->connectionName === 'pgsql_admin'
            || ! str_contains(strtolower($query->sql), 'from "tenants"')) {
            return;
        }
        $rivalFirst = false;   // set before running it, so the rival's own queries never re-enter
        $rivalFirst = prRivalChange(1, $rival->id);
    });

    app(ChangeCustomerPlan::class)->handle($customer, $mine, 'Đổi gói');

    expect($rivalFirst)->not->toBeNull();   // the rival did race the action
    if ($rivalFirst === false) {
        expect(prRivalChange(1, $rival->id))->toBeTrue();
    }

    $rows = DB::connection('pgsql_admin')->table('tenant_history')
        ->where('tenant_id', 1)->orderBy('created_at')->orderBy('id')
        ->get(['from_plan_id', 'to_plan_id'])
        ->map(fn (object $row): array => [$row->from_plan_id, $row->to_plan_id])->all();
    $now = DB::connection('pgsql_admin')->table('tenants')->where('id', 1)->value('plan_id');

    // One unbroken story: from the start plan, each change starting where the one before ended,
    // and the last ending on the plan the customer is on now.
    expect($rows)->toHaveCount(2)
        ->and($rows[0][0])->toBe($start->id)
        ->and($rows[1][0])->toBe($rows[0][1])
        ->and($rows[1][1])->toBe($now);
});
