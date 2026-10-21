<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * A provision refused because its hostname already serves a website of a different customer than
 * the one named. Re-running would make the owner an owner of that other customer's website, so
 * nothing is written. Its own type so no broad `catch (RuntimeException)` swallows it; the caller
 * explains it in plain words — never naming the other customer.
 */
final class HostnameOwnedByAnotherTenantException extends DomainException
{
    public function __construct(public readonly string $hostname)
    {
        parent::__construct("The hostname {$hostname} already belongs to a website of another customer.");
    }
}
