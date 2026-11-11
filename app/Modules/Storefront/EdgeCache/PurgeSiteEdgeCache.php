<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use App\Modules\Shared\Contracts\EdgeCache;
use App\Modules\Tenancy\Context\SiteDirectory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Drops every edge-cached page of one site after a change to what it shows. Dispatched after the
 * write commits (EdgeCachePurges). One waiting job per site: more writes while it waits add nothing,
 * and a write made while it runs queues the next one (the lock goes as processing starts), so the
 * purge always follows the last commit.
 *
 * Purge by host name first; when the edge refuses (Cloudflare limits host purges per minute) the
 * home pages go by URL, the edit a site owner looks at first, and the rest ages out within
 * `storefront.edge_cache.s_maxage`. When even that fails the job tries again a minute later.
 */
final class PurgeSiteEdgeCache implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $siteId) {}

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(EdgeCache $edge, SiteDirectory $sites): void
    {
        $hosts = $sites->hostsOf($this->siteId);
        if ($hosts === []) {
            return;
        }

        if ($edge->purgeHosts($hosts)) {
            return;
        }

        $homes = array_map(static fn (string $host): string => 'https://'.$host.'/', $hosts);
        if (! $edge->purgeUrls($homes) && $this->attempts() < $this->tries) {
            $this->release(60);
        }
    }
}
