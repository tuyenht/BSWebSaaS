<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * The shared cache in front of the public pages (a CDN edge). Pages leave the origin marked
 * `public, s-maxage=…`; after a change to what a site shows, its copies are dropped through this.
 * Lives in Shared\Contracts so the drivers can be swapped (Cloudflare, none) without the callers
 * knowing which one runs.
 */
interface EdgeCache
{
    /**
     * Drop every cached page of these host names. False when the edge refused or could not be
     * reached, so the caller can fall back to purgeUrls() or try again later.
     *
     * @param  list<string>  $hosts
     */
    public function purgeHosts(array $hosts): bool;

    /**
     * Drop the cached copies of these absolute URLs only.
     *
     * @param  list<string>  $urls
     */
    public function purgeUrls(array $urls): bool;
}
