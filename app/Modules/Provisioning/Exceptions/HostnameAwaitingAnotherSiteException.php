<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * A provision refused because its hostname is already attached to another website and still waits
 * for its DNS check. Only a verified name is unique, so ProvisionSite could insert it verified for
 * the new website, and the website really waiting for it could then never verify it. Nothing is
 * written. Its own type so no broad `catch (RuntimeException)` swallows it; the caller explains it
 * in plain words — never naming the other website or its customer.
 */
final class HostnameAwaitingAnotherSiteException extends DomainException
{
    public function __construct(public readonly string $hostname)
    {
        parent::__construct("The hostname {$hostname} is waiting to be verified for another website.");
    }
}
