<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A change outside the website's own content changed what its public pages show or where they
 * answer: its primary domain or running spelling, a domain removed, its customer's plan, its status.
 * Tenancy only announces it — the storefront drops its edge-cached pages. `$alsoHosts` names hosts
 * the website no longer has (a removed domain), whose cached pages must go too.
 *
 * Dispatched after the change commits, so a rolled-back change purges nothing.
 */
final readonly class PublicSiteChanged implements ShouldDispatchAfterCommit
{
    /** @param list<string> $alsoHosts */
    public function __construct(
        public int $siteId,
        public array $alsoHosts = [],
    ) {}
}
