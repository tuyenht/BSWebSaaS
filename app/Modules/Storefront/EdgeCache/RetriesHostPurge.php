<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use App\Modules\Shared\Contracts\EdgeCache;
use Illuminate\Support\Facades\Log;

/**
 * How a queued purge by host name is pursued. When the edge refuses it (Cloudflare limits host
 * purges per minute), the home page of each host goes by URL at once — the edit a site owner looks
 * at first — and the host purge is tried again later, a minute more each time: with pages kept a day
 * at the edge, settling for the homes would leave every other page stale until it expires. After
 * the last try the refusal is logged as an error and reported (EdgePurgeRefused), naming the hosts still stale.
 *
 * For a job that uses InteractsWithQueue.
 */
trait RetriesHostPurge
{
    public int $tries = 5;

    /** Seconds added to the wait before each new try. */
    private const int RETRY_STEP = 60;

    /**
     * @param  list<string>  $hosts  names inside the platform's zone
     * @param  array<string, mixed>  $context  what the error log names besides the hosts
     */
    private function purgeOrRetry(EdgeCache $edge, array $hosts, array $context = []): void
    {
        if ($hosts === [] || $edge->purgeHosts($hosts)) {
            return;
        }

        $edge->purgeUrls(array_map(static fn (string $host): string => 'https://'.$host.'/', $hosts));

        $attempt = $this->attempts();
        if ($attempt < $this->tries) {
            // a little spread, so refused batches do not all come back in the same second
            $this->release(self::RETRY_STEP * $attempt + random_int(0, intdiv(self::RETRY_STEP, 2)));

            return;
        }

        Log::error('The edge cache kept refusing a purge by host: these sites show old pages until they expire.', $context + ['hosts' => $hosts, 'attempts' => $attempt]);

        // Also to the exception reporting the monitoring summary counts, so a refused purge is seen.
        report(new EdgePurgeRefused($hosts, $attempt));
    }
}
