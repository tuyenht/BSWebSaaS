<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

/**
 * What DNS says about one spelling of a site's domain, gathered by CheckDomain::probe() before any
 * transaction opens, so no row lock — and, for a caller that enters the site itself, no tenant
 * transaction — is held across the lookups. CheckDomain::apply() writes it inside the site.
 *
 * `pointing` is null when the name points at the platform, else the reason it does not
 * (not_pointing · points_elsewhere); `proved` says the site's TXT record for this hostname is
 * published. `seen` is how the row stood when it was read for the probe (verified, failing, last
 * checked): a write that finds the row changed since then knows someone else checked it meanwhile.
 * `twin` is the other spelling, when the check will need it.
 */
final readonly class DomainProbe
{
    /** @param  'not_pointing'|'points_elsewhere'|null  $pointing */
    public function __construct(
        public int $siteId,
        public string $host,
        public string $txtName,
        public string $txtValue,
        public ?string $pointing,
        public bool $proved,
        public string $seen,
        public ?DomainProbe $twin = null,
    ) {}

    public function withTwin(DomainProbe $twin): self
    {
        return new self($this->siteId, $this->host, $this->txtName, $this->txtValue, $this->pointing, $this->proved, $this->seen, $twin);
    }
}
