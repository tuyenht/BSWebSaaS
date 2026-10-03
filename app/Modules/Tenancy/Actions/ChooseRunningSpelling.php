<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Choose which spelling of a name runs — `shop.example.com` or `www.shop.example.com` (design.md
 * §28, owner 03/10). The chosen one answers; the other answers 301 to it, path and query kept.
 *
 * When the spelling that ran was the site's primary domain, the chosen one takes its place in the
 * same transaction, so it must be able to answer visitors already: verified, with HTTPS on.
 *
 * Who may manage a site's domains is the caller's check. Runs inside the tenant context.
 */
final readonly class ChooseRunningSpelling
{
    public function __construct(
        private CurrentSite $current,
        private SiteResolver $sites,
    ) {}

    /**
     * @return Domain the spelling that now runs
     *
     * @throws DomainChangeRejectedException invalid · not_found · not_verified · https_not_on
     */
    public function handle(string $input): Domain
    {
        $host = DomainName::parse($input);
        $siteId = $this->current->id();

        $domain = DB::transaction(function () use ($host, $siteId): Domain {
            Site::query()->whereKey($siteId)->lockForUpdate()->value('id');

            $rows = Domain::query()
                ->where('site_id', $siteId)->where('kind', 'custom')
                ->whereIn('hostname', [$host, DomainName::sibling($host)])
                ->get();

            $chosen = $rows->firstWhere('hostname', $host);
            $other = $rows->firstWhere('hostname', DomainName::sibling($host));

            if ($chosen === null) {
                throw new DomainChangeRejectedException('not_found');
            }

            if (! $chosen->is_redirect) {
                return $chosen;
            }

            $takesPrimary = $other?->is_primary === true;

            if ($takesPrimary && $chosen->verified_at === null) {
                throw new DomainChangeRejectedException('not_verified');
            }

            if ($takesPrimary && $chosen->ssl_status !== 'active') {
                throw new DomainChangeRejectedException('https_not_on');
            }

            // The other spelling first: a site has one primary, and a redirect is never primary.
            $other?->forceFill(['is_redirect' => true, 'is_primary' => false])->save();
            $chosen->forceFill(['is_redirect' => false, 'is_primary' => $takesPrimary])->save();

            return $chosen;
        });

        // Both spellings changed role, and the site's primary may have moved.
        $this->sites->forgetSite($siteId);

        return $domain;
    }
}
