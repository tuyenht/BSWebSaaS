<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use RuntimeException;

/**
 * The edge kept refusing a purge by host through every try: these hosts serve old pages until they
 * expire. Reported (not thrown) so the exception reporting, and the monitoring summary that counts
 * it, show the fault.
 */
final class EdgePurgeRefused extends RuntimeException
{
    /** @param list<string> $hosts */
    public function __construct(public readonly array $hosts, int $attempts)
    {
        parent::__construct(sprintf(
            'The edge cache refused a purge by host after %d tries; stale pages stay until they expire: %s',
            $attempts,
            implode(', ', array_slice($hosts, 0, 10)).(count($hosts) > 10 ? sprintf(' and %d more', count($hosts) - 10) : ''),
        ));
    }
}
