<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

/**
 * What a verified hostname resolves to (SiteResolver): the site it serves, whether it is the
 * platform's own subdomain or a customer's domain, whether HTTPS has been turned on for it, and
 * the site's primary domain — the one address its public pages live on.
 */
final readonly class ResolvedHost
{
    public function __construct(
        public int $siteId,
        public bool $isPlatformSubdomain,
        public bool $httpsOn,
        public string $primaryHost,
    ) {}

    /**
     * May a certificate be issued for this host? A platform subdomain always; a customer's domain
     * only once its owner turned HTTPS on (design.md §28 — HTTPS is a step of its own).
     */
    public function mayHaveCertificate(): bool
    {
        return $this->isPlatformSubdomain || $this->httpsOn;
    }
}
