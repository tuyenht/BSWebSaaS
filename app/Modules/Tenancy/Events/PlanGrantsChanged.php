<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * What a plan grants changed (SetPlanFeatures): every website whose customer is on it may show
 * different modules now. Tenancy only announces it — the storefront drops those websites' edge-cached
 * pages. Dispatched after the change commits.
 */
final readonly class PlanGrantsChanged implements ShouldDispatchAfterCommit
{
    public function __construct(public int $planId) {}
}
