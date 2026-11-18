<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use App\Modules\Shared\Contracts\EdgeCache;
use App\Modules\Tenancy\Events\DomainNeedsAttention;
use App\Modules\Tenancy\Events\PlanGrantsChanged;
use App\Modules\Tenancy\Events\PublicSiteChanged;

/**
 * The changes made outside a website's tenant context that still change its public pages, which the
 * query listener (EdgeCachePurges) never sees: Tenancy announces them after they commit, and each
 * queues the purge of the websites concerned. With no edge configured nothing is queued.
 *
 * A domain notice counts when it changes where the website's pages answer: a live name that starts
 * or ends failing (a failing primary hands the site's pages back to its other hosts, a recovered one
 * takes them again), a name lost or taken over by another site (its primary falls back).
 */
final readonly class PurgeOnPublicChange
{
    /** The DomainNeedsAttention variants that move where the pages answer; the last two lose the names. */
    private const array DOMAIN_VARIANTS = ['failing', 'recovered', 'lost', 'taken_over'];

    public function __construct(private EdgeCache $edge) {}

    public function site(PublicSiteChanged $event): void
    {
        if (! $this->edge instanceof NullEdgeCache) {
            PurgeSiteEdgeCache::dispatch($event->siteId, $event->alsoHosts);
        }
    }

    public function plan(PlanGrantsChanged $event): void
    {
        if (! $this->edge instanceof NullEdgeCache) {
            PurgePlanEdgeCache::dispatch($event->planId);
        }
    }

    public function domain(DomainNeedsAttention $event): void
    {
        if ($this->edge instanceof NullEdgeCache || ! in_array($event->variant, self::DOMAIN_VARIANTS, true)) {
            return;
        }

        $lost = $event->variant === 'lost' || $event->variant === 'taken_over';
        PurgeSiteEdgeCache::dispatch($event->siteId, $lost ? $event->hostnames : []);
    }
}
