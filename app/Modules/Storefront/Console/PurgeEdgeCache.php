<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Console;

use App\Modules\Shared\Contracts\EdgeCache;
use App\Modules\Shared\Support\Cast;
use App\Modules\Storefront\EdgeCache\EdgeHostBatches;
use App\Modules\Storefront\EdgeCache\NullEdgeCache;
use App\Modules\Tenancy\Context\SiteDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Queue the purge of edge-cached pages by host name, 100 names per Cloudflare call (PurgeEdgeHosts):
 * of every website (`--all`, after a deploy that changes how pages look), or of the websites named
 * by id or by any of their host names (`--site=12 --site=congty.baoson.net`). Only names inside the
 * platform's zone are purged. Domains are read by id, a chunk at a time. With no edge configured it
 * says so and does nothing.
 */
final class PurgeEdgeCache extends Command
{
    protected $signature = 'storefront:purge-edge
        {--all : every website that has a host name inside the platform zone}
        {--site=* : a website id or one of its host names (repeatable)}';

    protected $description = 'Queue the purge of edge-cached storefront pages (Cloudflare), 100 host names per call';

    /** Domain rows read at a time with --all. */
    private const int CHUNK = 1000;

    public function handle(EdgeCache $edge, SiteDirectory $sites): int
    {
        if ($edge instanceof NullEdgeCache) {
            $this->info('No edge cache is configured (no Cloudflare zone or purge token): nothing to purge.');

            return self::SUCCESS;
        }

        $all = (bool) $this->option('all');
        $named = Cast::strings($this->option('site'));
        if (! $all && $named === []) {
            $this->error('Name the websites: --all, or --site=<id or host name>.');

            return self::INVALID;
        }

        $batches = new EdgeHostBatches;
        $missing = 0;
        if ($all) {
            $sites->eachHostChunk(self::CHUNK, $batches->add(...));
        } else {
            foreach ($named as $site) {
                $site = trim($site);
                $hosts = ctype_digit($site) ? $sites->hostsOf((int) $site) : $sites->hostsOfSiteWith($site);
                if ($hosts === []) {
                    $this->warn("No website has the id or host name \"{$site}\".");
                    $missing++;

                    continue;
                }
                $batches->add($hosts);
            }
        }
        $batches->flush();

        $this->info(sprintf('Queued the purge of %d host names in %d %s.', $batches->hosts, $batches->jobs, Str::plural('job', $batches->jobs)));

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
