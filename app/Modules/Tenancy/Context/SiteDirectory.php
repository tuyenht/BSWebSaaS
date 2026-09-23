<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;

/**
 * Public read surface over the GLOBAL site registry (sites + domains — D6, no RLS) for callers
 * that run outside a tenant context and so cannot lean on CurrentSite — e.g. an error page,
 * rendered after Tenancy::enter has already cleared the context. Feature modules use this
 * instead of reaching into Tenancy\Models (app/Modules/README.md). Read-only, and it hands
 * back names, never ids.
 */
final readonly class SiteDirectory
{
    /**
     * The display name of the site that owns $host, or null when the host is not a site domain.
     */
    public function nameForHost(string $host): ?string
    {
        $name = Site::query()
            ->whereIn('id', Domain::query()->select('site_id')->where('hostname', $host))
            ->value('name');

        return is_string($name) && trim($name) !== '' ? $name : null;
    }
}
