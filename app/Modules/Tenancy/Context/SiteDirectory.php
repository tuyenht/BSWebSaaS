<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\Site;

/**
 * Public read surface over the GLOBAL site registry (sites + domains — D6, no RLS): by host for
 * callers that run outside a tenant context and so cannot lean on CurrentSite — e.g. an error
 * page, rendered after Tenancy::enter has already cleared the context — and for the current site
 * inside one. Feature modules use this instead of reaching into Tenancy\Models
 * (app/Modules/README.md). Read-only, and it hands back names, never ids.
 */
final readonly class SiteDirectory
{
    public function __construct(private SiteResolver $sites) {}

    /**
     * The display name of the site that owns $host, or null when the host is not a site domain.
     */
    public function nameForHost(string $host): ?string
    {
        $resolved = $this->sites->resolve($host);

        if ($resolved === null) {
            return null;
        }

        $name = Site::query()->whereKey($resolved->siteId)->value('name');

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * The stored name of the current site, exactly as saved ('' when unset) — media storage keys
     * are prefixed with it, so it is never trimmed or replaced by a display name. Takes the context
     * rather than an id: it reads the current site's own row.
     */
    public function nameOfCurrent(CurrentSite $current): string
    {
        return Cast::string(Site::query()->whereKey($current->id())->value('name'));
    }
}
