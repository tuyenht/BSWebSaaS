<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * A provision refused because its hostname is not one SiteResolver can ever match (empty, an IP, a
 * single label, characters outside letters/digits/hyphen). Stored, it would be a verified primary
 * domain of a website nobody can reach, still counting toward the customer's `sites.max`; so nothing
 * is written. Its own type so no broad `catch (RuntimeException)` swallows it; the caller explains it
 * in plain words with `provisioning.host_invalid`, quoting the name as it was given.
 */
final class UnusableHostnameException extends DomainException
{
    public function __construct(public readonly string $hostname)
    {
        parent::__construct("The hostname \"{$hostname}\" is not one a website can use.");
    }
}
