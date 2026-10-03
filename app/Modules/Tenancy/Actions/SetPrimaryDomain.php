<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Make one of the current site's domains its primary — the address the site is known by
 * (design.md §28). A site has exactly one, and the switch is one transaction, so it never has
 * none or two:
 *
 *  - only a verified domain can be primary, and a customer's own domain only once its HTTPS is
 *    on — the primary is where every visitor is sent, it must answer;
 *  - the previous primary stays attached; it simply stops being the one.
 *
 * Who may manage a site's domains is the caller's check. Runs inside the tenant context.
 */
final readonly class SetPrimaryDomain
{
    public function __construct(
        private CurrentSite $current,
        private SiteResolver $sites,
    ) {}

    /**
     * @throws DomainChangeRejectedException invalid · not_found · not_verified · redirect_only · https_not_on
     */
    public function handle(string $input): Domain
    {
        $host = DomainName::parse($input);
        $siteId = $this->current->id();

        [$domain, $hosts] = DB::transaction(function () use ($host, $siteId): array {
            Site::query()->whereKey($siteId)->lockForUpdate()->value('id');

            $rows = Domain::query()->where('site_id', $siteId)->get();
            $domain = $rows->firstWhere('hostname', $host);

            if ($domain === null) {
                throw new DomainChangeRejectedException('not_found');
            }

            if ($domain->verified_at === null) {
                throw new DomainChangeRejectedException('not_verified');
            }

            // The spelling that only redirects never runs, so it cannot be the site's address.
            if ($domain->is_redirect) {
                throw new DomainChangeRejectedException('redirect_only');
            }

            if ($domain->kind === 'custom' && $domain->ssl_status !== 'active') {
                throw new DomainChangeRejectedException('https_not_on');
            }

            if (! $domain->is_primary) {
                // Clear first: the database allows one primary per site at any moment.
                Domain::query()->where('site_id', $siteId)->where('is_primary', true)->update(['is_primary' => false]);
                $domain->forceFill(['is_primary' => true])->save();
            }

            return [$domain, Cast::strings($rows->pluck('hostname')->all())];
        });

        $this->sites->forget(...$hosts);

        return $domain;
    }
}
