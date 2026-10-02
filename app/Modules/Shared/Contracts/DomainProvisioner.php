<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Whatever puts a certificate behind a customer's domain (ADR-07). The business code only says
 * "this verified domain should have HTTPS now"; how that happens — the web server requesting a
 * certificate on demand today, a hosted edge service later — stays behind this interface.
 */
interface DomainProvisioner
{
    /**
     * Have a certificate in place for the host. Safe to call again for a host that already has
     * one. Returns null when HTTPS answers with a valid certificate, else a reason code the
     * screens translate (e.g. `certificate_not_issued`).
     */
    public function ensureCertificate(string $host): ?string;
}
