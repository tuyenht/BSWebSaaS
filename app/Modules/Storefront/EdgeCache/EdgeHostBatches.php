<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

/**
 * Gathers host names into PurgeEdgeHosts jobs of 30 (the most one Cloudflare purge call takes below
 * Enterprise), keeping only names inside the platform's zone, each once within a job. Call flush() after the last
 * add(); the counts say what was queued.
 */
final class EdgeHostBatches
{
    public const int PER_JOB = 30;

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

        PurgeEdgeHosts::dispatch(array_map('strval', array_keys($this->pending)));
        $this->jobs++;
        $this->pending = [];
    }
}
