<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Domains\PrimaryDomain;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Remove a customer's own domain from the current site (design.md §28). A root domain and its
 * `www` leave together — they were attached as one. If the primary domain is among them, the
 * site's platform subdomain becomes primary in the same transaction, so the site is never left
 * without an address. The platform subdomain itself cannot be removed.
 *
 * The removed names are forgotten at once: they stop resolving and stop being issued certificates.
 * Nothing is held back afterwards — the caller reminds the owner to delete the DNS record.
 *
 * Who may manage a site's domains is the caller's check. Runs inside the tenant context.
 */
final readonly class RemoveDomain
{
    public function __construct(
        private CurrentSite $current,
        private SiteResolver $sites,
    ) {}

    /**
     * @return list<string> the hostnames removed
     *
     * @throws DomainChangeRejectedException invalid · not_found · platform_subdomain
     */
    public function handle(string $input): array
    {
        $host = DomainName::parse($input);
        $siteId = $this->current->id();

        $removed = DB::transaction(function () use ($host, $siteId): array {
            Site::query()->whereKey($siteId)->lockForUpdate()->value('id');

            $rows = Domain::query()
                ->where('site_id', $siteId)
                ->whereIn('hostname', [$host, DomainName::sibling($host)])
                ->get();

            $asked = $rows->firstWhere('hostname', $host);

            if ($asked === null) {
                throw new DomainChangeRejectedException('not_found');
            }

            if ($asked->kind !== 'custom') {
                throw new DomainChangeRejectedException('platform_subdomain');
            }

            $removed = [];

            foreach ($rows->where('kind', 'custom') as $row) {
                $row->delete();
                $removed[] = $row->hostname;
            }

            PrimaryDomain::fallBackToSubdomain($siteId);

            return $removed;
        });

        $this->sites->forget(...$removed);

        return $removed;
    }
}
