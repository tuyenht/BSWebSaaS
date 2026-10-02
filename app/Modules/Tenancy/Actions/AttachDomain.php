<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Domains\DnsLookup;
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
 *  - a root domain brings its `www` along (and `www.` brings its root), and the pair counts as ONE
 *    against the plan's `custom_domains.max`;
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
        private DnsLookup $dns,
    ) {}

    /**
     * @return list<Domain> the rows created — the name asked for first
     *
     * @throws DomainChangeRejectedException invalid · reserved · already_attached · limit_reached
     */
    public function handle(string $input, ?PointingMethod $pointing = null): array
    {
        $host = DomainName::parse($input);

        if (DomainName::isReserved($host)) {
            throw new DomainChangeRejectedException('reserved');
        }

        $names = [$host, ...$this->pairedName($host)];
        $siteId = $this->current->id();

        return DB::transaction(function () use ($siteId, $host, $names, $pointing): array {
            // Serialize a site's domain changes: two concurrent attaches must not both pass the limit.
            Site::query()->whereKey($siteId)->lockForUpdate()->value('id');

            $existing = Cast::strings(Domain::query()->where('site_id', $siteId)->where('kind', 'custom')->pluck('hostname')->all());

            if (in_array($host, $existing, true)) {
                throw new DomainChangeRejectedException('already_attached');
            }

            $pairs = array_unique(array_map(DomainName::pairKey(...), $existing));

            if (! in_array(DomainName::pairKey($host), $pairs, true)
                && count($pairs) >= (int) $this->entitlement->featureValue('custom_domains.max')) {
                throw new DomainChangeRejectedException('limit_reached');
            }

            $created = [];

            foreach ($names as $name) {
                if (in_array($name, $existing, true)) {
                    continue;
                }

                $created[] = Domain::query()->create([
                    'site_id' => $siteId,
                    'hostname' => $name,
                    'kind' => 'custom',
                    'is_primary' => false,
                    'pointing_method' => $pointing?->value,
                ]);
            }

            return $created;
        });
    }

    /**
     * The other half of a root-and-www pair, when there is one. Only a root domain has a `www`
     * worth serving: `shop.example.com` does not get `www.shop.example.com`.
     *
     * @return list<string>
     */
    private function pairedName(string $host): array
    {
        $root = DomainName::pairKey($host);

        if (! $this->dns->isZoneApex($root)) {
            return [];
        }

        $sibling = DomainName::sibling($host);

        return DomainName::isReserved($sibling) ? [] : [$sibling];
    }
}
