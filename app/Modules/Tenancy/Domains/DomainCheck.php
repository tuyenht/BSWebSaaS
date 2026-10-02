<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

/**
 * The outcome of checking a customer's domain (CheckDomain). `status` is one of:
 *
 *   verified         the domain points at the platform and now belongs to this site;
 *   not_pointing     the domain resolves to nothing;
 *   points_elsewhere the domain resolves, but not (only) to the platform's server;
 *   needs_proof      it points here, but another site also claims it — the owner proves the
 *                    domain is theirs by publishing the TXT record named here.
 *
 * `evictedSiteIds` lists the sites that lost the domain because this site proved ownership.
 */
final readonly class DomainCheck
{
    /**
     * @param  'verified'|'not_pointing'|'points_elsewhere'|'needs_proof'  $status
     * @param  list<int>  $evictedSiteIds
     */
    public function __construct(
        public string $status,
        public string $txtName,
        public string $txtValue,
        public array $evictedSiteIds = [],
    ) {}

    public function verified(): bool
    {
        return $this->status === 'verified';
    }
}
