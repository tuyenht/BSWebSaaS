<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use App\Modules\Shared\Contracts\EdgeCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Drops every edge-cached page of a batch of host names, whatever sites they belong to: one
 * Cloudflare call for up to 100 names (EdgeHostBatches fills them). Queued by `storefront:purge-edge`
 * and by a change to a plan's grants. Only names inside the platform's zone go; a refusal is pursued
 * as RetriesHostPurge says.
 */
final class PurgeEdgeHosts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RetriesHostPurge;

    /** @param list<string> $hosts */
    public function __construct(public readonly array $hosts) {}

    public function handle(EdgeCache $edge): void
    {
        $this->purgeOrRetry($edge, PurgeSiteEdgeCache::inZone($this->hosts));
    }
}
