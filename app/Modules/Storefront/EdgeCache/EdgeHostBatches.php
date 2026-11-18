<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use App\Modules\Shared\Support\Cast;

/**
 * Gathers host names into PurgeEdgeHosts jobs of 100 (the most one Cloudflare purge call takes on any plan),
 * keeping only names inside the platform's zone, each once within a job. Call flush() after the last
 * add(); the counts say what was queued.
 */
final class EdgeHostBatches
{
    public const int PER_JOB = 100;

    public int $hosts = 0;

    public int $jobs = 0;

    /** @var array<string, true> */
    private array $pending = [];

    /** @param list<string> $hosts */
    public function add(array $hosts): void
    {
        foreach (PurgeSiteEdgeCache::inZone($hosts) as $host) {
            $host = strtolower(rtrim($host, '.'));
            if (isset($this->pending[$host])) {
                continue;
            }
            $this->pending[$host] = true;
            $this->hosts++;
            if (count($this->pending) === self::PER_JOB) {
                $this->flush();
            }
        }
    }

    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        // Paced: a burst (every site after a deploy) must neither run into the zone's purge rate limit at
        // once nor hold up the purges a site's own edits queue meanwhile.
        PurgeEdgeHosts::dispatch(array_map('strval', array_keys($this->pending)))
            ->delay($this->jobs * max(0, Cast::int(config('storefront.edge_cache.purge_pace_seconds'), 15)));
        $this->jobs++;
        $this->pending = [];
    }
}
