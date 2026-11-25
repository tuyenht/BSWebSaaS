<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The end time («Gỡ khỏi website lúc») of the item this response shows, so the edge never keeps the
 * page past it: EdgeCacheHeaders cuts the page's s-maxage to the seconds left and gives it no stale
 * window. The sweep that takes the item down purges the edge anyway; this holds even when that purge
 * fails, or for a host our purge cannot name. Set by the detail page (post, page, product); scoped
 * per request (Octane), so a page without one keeps the usual lifetime.
 */
final class PageEndTime
{
    private ?CarbonImmutable $at = null;

    public function until(?DateTimeInterface $at): void
    {
        $this->at = $at !== null ? CarbonImmutable::instance($at) : null;
    }

    /** Seconds until the end time (at least 1), or null when the page has none. */
    public function secondsLeft(): ?int
    {
        if ($this->at === null) {
            return null;
        }

        return max(1, $this->at->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }
}
