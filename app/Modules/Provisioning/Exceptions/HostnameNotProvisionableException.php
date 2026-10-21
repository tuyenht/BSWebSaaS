<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * A provision refused because its hostname cannot become a new website's platform subdomain, so
 * nothing is written. ProvisionSite stamps that subdomain verified from birth, which is only true
 * of a name the platform owns:
 *
 *  - `invalid` — not a hostname at all;
 *  - `not_platform_subdomain` — not `{slug}.{base}` under a platform base domain (a customer's own
 *    domain, the bare base, a nested name, or one of the platform's own hosts);
 *  - `pending_elsewhere` — a website is still waiting to prove it owns this name (a base added to
 *    the config after a customer had attached a name under it).
 *
 * Its own type so no broad `catch (RuntimeException)` swallows it; the caller explains the reason
 * in plain words — never naming the other customer.
 */
final class HostnameNotProvisionableException extends DomainException
{
    /**
     * @param  'invalid'|'not_platform_subdomain'|'pending_elsewhere'  $reason
     */
    public function __construct(
        public readonly string $hostname,
        public readonly string $reason,
    ) {
        parent::__construct("The hostname {$hostname} cannot be provisioned: {$reason}.");
    }
}
