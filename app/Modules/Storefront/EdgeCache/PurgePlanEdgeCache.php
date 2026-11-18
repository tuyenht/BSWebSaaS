<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use App\Modules\Tenancy\Context\SiteDirectory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Drops the edge-cached pages of every website whose customer is on one plan, after what the plan
 * grants changed (PlanGrantsChanged). Walks the domains by id and queues their in-zone names in
 * PurgeEdgeHosts jobs of 30, so a plan with thousands of websites never loads them at once or sends
 * one call per site. One waiting job per plan.
 */
final class PurgePlanEdgeCache implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Domain rows read at a time. */
    private const int CHUNK = 1000;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $planId) {}

    public function uniqueId(): string
    {
        return (string) $this->planId;
    }

    public function handle(SiteDirectory $sites): void
    {
        $batches = new EdgeHostBatches;
        $sites->eachHostChunk(self::CHUNK, $batches->add(...), $this->planId);
        $batches->flush();
    }
}
