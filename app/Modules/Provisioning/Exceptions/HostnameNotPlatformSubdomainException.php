<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * A provision refused because its hostname is not a platform subdomain ({slug}.{base}). A new
 * site's first domain is verified from birth, which only a name the platform owns may be; any
 * other name — a customer's domain, perhaps one another site is waiting to verify — must be proven
 * through `site:domain … attach` (design.md §28). Nothing is written. Its own type so no broad
 * `catch (RuntimeException)` swallows it; the caller explains it in plain words. Not final: a
 * reserved label is the same refusal with a more precise reason (HostnameReservedLabelException).
 */
class HostnameNotPlatformSubdomainException extends DomainException
{
    public function __construct(public readonly string $hostname, ?string $message = null)
    {
        parent::__construct($message ?? "The hostname {$hostname} is not a platform subdomain.");
    }
}
