<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use Illuminate\Support\Str;

/**
 * Create a plan, or change the name of one and whether it is still sold (design.md §15.2). `plans`
 * is a GLOBAL catalog (D6): no tenant context.
 *
 *  - a plan has a name;
 *  - its key is made from the name once, at creation, and never changes — customers and the trail
 *    refer to it. A taken key gets a short random suffix;
 *  - a new plan goes last in the list;
 *  - a plan that is no longer sold keeps its customers: it only cannot be given to a new one.
 *
 * Who may edit the catalog is the caller's check.
 */
final readonly class SavePlan
{
    /**
     * @throws PlanChangeRejectedException name_required
     */
    public function handle(?Plan $plan, string $name, bool $isActive): Plan
    {
        $name = trim($name);

        if ($name === '') {
            throw new PlanChangeRejectedException('name_required');
        }

        if ($plan === null) {
            $plan = new Plan(['key' => $this->freeKey($name), 'sort_order' => Cast::int(Plan::query()->max('sort_order')) + 1]);
        }

        $plan->forceFill(['name' => $name, 'is_active' => $isActive])->save();

        return $plan;
    }

    private function freeKey(string $name): string
    {
        $base = Slugger::make($name);
        $base = $base === '' ? 'plan' : $base;

        $key = $base;
        while (Plan::query()->where('key', $key)->exists()) {
            $key = $base.'-'.Str::lower(Str::random(4));
        }

        return $key;
    }
}
