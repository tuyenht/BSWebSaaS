<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantHistory;
use Illuminate\Support\Facades\DB;

/**
 * Put a customer on a plan, or on none (design.md §15.2). The plan belongs to the customer, so
 * every website of theirs follows; a website's own feature values (`site_features`) stay as they
 * are and keep winning over the plan.
 *
 * A plan that is no longer sold cannot be given to a customer who is not already on it. What the
 * customer's websites already have above the new plan's limits stays; only adding more is blocked.
 *
 * A real change leaves a `plan_changed` row in the customer's history (tenant_history) in the same
 * transaction, so the plan and its history never disagree; staying on the same plan changes
 * nothing and records nothing. The customer row is locked before its plan is read, so two changes
 * at once each record the plan the other left.
 *
 * Who may change a customer's plan, and checking the reason (at most 500 characters), are the
 * caller's; so is the audit entry.
 */
final readonly class ChangeCustomerPlan
{
    /**
     * @param  int|null  $actorUserId  who made the change; null from the console
     * @return int|null the id of the plan the customer was on
     *
     * @throws PlanChangeRejectedException plan_not_sold
     */
    public function handle(Tenant $customer, ?Plan $plan, ?string $reason = null, ?int $actorUserId = null): ?int
    {
        $to = $plan?->id;

        $was = DB::transaction(function () use ($customer, $plan, $to, $reason, $actorUserId): ?int {
            $locked = Tenant::query()->withTrashed()->whereKey($customer->id)->lockForUpdate()->firstOrFail(['id', 'plan_id']);
            $was = $locked->plan_id;

            if ($plan !== null && ! $plan->is_active && $to !== $was) {
                throw new PlanChangeRejectedException('plan_not_sold');
            }

            if ($to === $was) {
                return $was;
            }

            Tenant::query()->withTrashed()->whereKey($customer->id)->update(['plan_id' => $to]);

            TenantHistory::query()->create([
                'tenant_id' => $customer->id,
                'kind' => TenantHistory::KIND_PLAN_CHANGED,
                'from_plan_id' => $was,
                'to_plan_id' => $to,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
            ]);

            return $was;
        });

        $customer->forceFill(['plan_id' => $to])->syncOriginalAttribute('plan_id');

        return $was;
    }
}
