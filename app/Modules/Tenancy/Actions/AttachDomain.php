<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Domains\PointingMethod;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Attach a customer's own domain to the current site (design.md §28). The single write path behind
 * the site's Domains screen, the platform console and the operator command, so the rules hold
 * whoever calls it:
 *
 *  - the name is stored as a plain lower-case hostname (punycode for names with diacritics);
 *  - names that belong to the platform can never be attached;
 *  - every name comes as a pair of spellings, with and without `www.` (`shop.example.com` and
 *    `www.shop.example.com`). The owner picks the one that runs — by default the one without
 *    `www.` — and the other only redirects to it (is_redirect). The pair counts as ONE against
 *    the plan's `custom_domains.max`;
 *  - the new rows are only WAITING: they resolve to nothing and get no certificate until
 *    CheckDomain sees the domain pointing at the platform. A waiting row holds no claim — another
 *    site may wait for the same name.
 *
 * Who may manage a site's domains is the caller's check. Runs inside the tenant context.
 */
final readonly class AttachDomain
{
    public function __construct(
        private CurrentSite $current,
        private Entitlement $entitlement,
    ) {}

    /**
     * @param  bool  $useWww  run the `www.` spelling and redirect the bare one to it
     * @return list<Domain> the rows created — the spelling that runs first
     *
     * @throws DomainChangeRejectedException invalid · reserved · already_attached · limit_reached
     */
    public function handle(string $input, ?PointingMethod $pointing = null, bool $useWww = false): array
    {
        $host = DomainName::parse($input);
        $bare = DomainName::pairKey($host);
        $runs = $useWww ? 'www.'.$bare : $bare;

        if (DomainName::isReserved($host) || DomainName::isReserved($runs)) {
            throw new DomainChangeRejectedException('reserved');
        }

        if (SiteResolver::normalize($runs) === null) {
            throw new DomainChangeRejectedException('invalid');
        }

        $twin = DomainName::sibling($runs);
        $names = [$runs => false];

        if (SiteResolver::normalize($twin) !== null && ! DomainName::isReserved($twin)) {
            $names[$twin] = true;
        }

        $siteId = $this->current->id();

        return DB::transaction(function () use ($siteId, $names, $pointing): array {
            // Serialize a site's domain changes: two concurrent attaches must not both pass the limit.
            Site::query()->whereKey($siteId)->lockForUpdate()->value('id');

            $existing = Cast::strings(Domain::query()->where('site_id', $siteId)->where('kind', 'custom')->pluck('hostname')->all());

            if (array_intersect(array_keys($names), $existing) !== []) {
                throw new DomainChangeRejectedException('already_attached');
            }

            $pairs = array_unique(array_map(DomainName::pairKey(...), $existing));

            if (count($pairs) >= (int) $this->entitlement->featureValue('custom_domains.max')) {
                throw new DomainChangeRejectedException('limit_reached');
            }

            $created = [];

            foreach ($names as $name => $redirects) {
                $created[] = Domain::query()->create([
                    'site_id' => $siteId,
                    'hostname' => $name,
                    'kind' => 'custom',
                    'is_primary' => false,
                    'is_redirect' => $redirects,
                    'pointing_method' => $pointing?->value,
                ]);
            }

            return $created;
        });
    }
}
