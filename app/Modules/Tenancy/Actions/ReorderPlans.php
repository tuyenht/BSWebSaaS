<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Put the plans in the given order (the order they are listed and offered in). The list must name
 * every plan exactly once: a stale list from a screen opened before a plan was added is refused
 * rather than half-applied.
 */
final readonly class ReorderPlans
{
    /**
     * @param  list<int>  $planIds  every plan id, first to last
     *
     * @throws PlanChangeRejectedException not_all_plans
     */
    public function handle(array $planIds): void
    {
        DB::transaction(function () use ($planIds): void {
            $existing = Plan::query()->lockForUpdate()->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->sort()->values()->all();
            $given = $planIds;
            sort($given);

            if ($given !== $existing) {
                throw new PlanChangeRejectedException('not_all_plans');
            }

            foreach ($planIds as $position => $id) {
                Plan::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }
}
