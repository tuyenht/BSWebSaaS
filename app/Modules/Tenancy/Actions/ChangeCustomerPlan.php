<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Put a customer on a plan, or on none (design.md §15.2). The plan belongs to the customer, so
 * every website of theirs follows; a website's own feature values (`site_features`) stay as they
 * are and keep winning over the plan.
 *
 * A plan that is no longer sold cannot be given to a customer who is not already on it. What the
 * customer's websites already have above the new plan's limits stays; only adding more is blocked.
 *
 * Who may change a customer's plan, and the reason on record, are the caller's.
 */
final readonly class ChangeCustomerPlan
{
    /**
     * @return int|null the id of the plan the customer was on
     *
     * @throws PlanChangeRejectedException plan_not_sold
     */
    public function handle(Tenant $customer, ?Plan $plan): ?int
    {
        $was = $customer->plan_id;

        if ($plan !== null && ! $plan->is_active && $plan->id !== $was) {
            throw new PlanChangeRejectedException('plan_not_sold');
        }

        $customer->forceFill(['plan_id' => $plan?->id])->save();

        return $was;
    }
}
