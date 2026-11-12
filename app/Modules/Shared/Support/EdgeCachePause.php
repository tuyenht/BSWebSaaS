<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Closure;

/**
 * Work that writes a public table without changing what a visitor sees (the page-view tally that
 * entries:flush-views adds to entries.view_count) runs inside during(): the edge-cache purge hook
 * skips every write made meanwhile, so it neither drops the site's cached pages nor spends the
 * zone's purge allowance. Bound scoped (StorefrontServiceProvider), so a pause never outlives its
 * request or job under Octane.
 */
final class EdgeCachePause
{
    private int $depth = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function during(Closure $work): mixed
    {
        $this->depth++;

        try {
            return $work();
        } finally {
            $this->depth--;
        }
    }

    public function active(): bool
    {
        return $this->depth > 0;
    }
}
