<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Domains\DnsLookup;
use App\Modules\Tenancy\Domains\DomainCheck;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Domains\OwnershipProof;
use App\Modules\Tenancy\Domains\PrimaryDomain;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Support\Facades\DB;

/**
 * Check a domain attached to the current site and, when it points at the platform, verify it
 * (design.md §28). Verification is what lets the domain be served and issued a certificate.
 *
 *  - Pointing at the platform = every address the domain resolves to is an address of the target
 *    host. That alone verifies a domain nobody else claims.
 *  - A domain another site also claims — waiting there, or live there — is CONTESTED: pointing
 *    here is not enough, because the shared target cannot tell which site the owner meant. The
 *    site that publishes its own TXT record keeps the domain; every other site loses it. That
 *    proof always wins, so the real owner can take a domain back from whoever holds it.
 *  - A domain already verified on this site stays verified while it points here: a newcomer
 *    waiting for the same name does not push the holder back to proving.
 *
 * Returns the outcome instead of throwing for DNS states — those are ordinary, the caller shows
 * them. Who may manage a site's domains is the caller's check. Runs inside the tenant context.
 */
final readonly class CheckDomain
{
    public function __construct(
        private CurrentSite $current,
        private DnsLookup $dns,
        private OwnershipProof $proof,
        private SiteResolver $sites,
    ) {}

    /**
     * @throws DomainChangeRejectedException invalid · not_found
     */
    public function handle(string $input): DomainCheck
    {
        $host = DomainName::parse($input);
        $siteId = $this->current->id();
        $txtName = $this->proof->recordName($host);
        $txtValue = $this->proof->recordValue($siteId, $host);

        $pointing = $this->pointing($host);
        $proved = $pointing === null && $this->proof->published($siteId, $host);

        $check = DB::transaction(function () use ($host, $siteId, $txtName, $txtValue, $pointing, $proved): DomainCheck {
            // Every site's row for this name, locked: two sites checking at once must not both win.
            $rows = Domain::query()->where('hostname', $host)->where('kind', 'custom')->lockForUpdate()->get();
            $mine = $rows->firstWhere('site_id', $siteId);

            if ($mine === null) {
                throw new DomainChangeRejectedException('not_found');
            }

            if ($pointing !== null) {
                $mine->forceFill([
                    'checked_at' => now(),
                    'failure_reason' => $pointing,
                    'failing_since' => $mine->verified_at !== null ? ($mine->failing_since ?? now()) : null,
                ])->save();

                return new DomainCheck($pointing, $txtName, $txtValue);
            }

            $others = $rows->where('site_id', '!==', $siteId);

            if ($mine->verified_at === null && $others->isNotEmpty() && ! $proved) {
                $mine->forceFill(['checked_at' => now(), 'failure_reason' => 'needs_proof'])->save();

                return new DomainCheck('needs_proof', $txtName, $txtValue);
            }

            $evicted = [];

            if ($mine->verified_at === null) {
                foreach ($others as $other) {
                    $other->delete();
                    PrimaryDomain::fallBackToSubdomain($other->site_id);
                    $evicted[] = $other->site_id;
                }
            }

            $mine->forceFill([
                'verified_at' => $mine->verified_at ?? now(),
                'checked_at' => now(),
                'failure_reason' => null,
                'failing_since' => null,
            ])->save();

            return new DomainCheck('verified', $txtName, $txtValue, array_values(array_unique($evicted)));
        });

        $this->sites->forget($host);

        return $check;
    }

    /**
     * Null when the domain points at the platform, else why not.
     *
     * @return 'not_pointing'|'points_elsewhere'|null
     */
    private function pointing(string $host): ?string
    {
        $addresses = $this->dns->addresses($host);

        if ($addresses === []) {
            return 'not_pointing';
        }

        $target = $this->dns->addresses(Cast::string(config('tenancy.custom_domains.target')));

        return array_diff($addresses, $target) === [] ? null : 'points_elsewhere';
    }
}
