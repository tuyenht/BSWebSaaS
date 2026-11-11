<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use App\Modules\Shared\Contracts\EdgeCache;

/**
 * No edge to purge (local, CI, or no Cloudflare token yet): cached pages simply age out after
 * `storefront.edge_cache.s_maxage` seconds.
 */
final class NullEdgeCache implements EdgeCache
{
    public function purgeHosts(array $hosts): bool
    {
        return true;
    }

    public function purgeUrls(array $urls): bool
    {
        return true;
    }
}
