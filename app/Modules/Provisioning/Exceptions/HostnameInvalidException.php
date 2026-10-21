<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * A provision refused because its hostname is not one SiteResolver can ever match (SiteResolver::
 * normalize() gives null: empty, too long, an IP, a single label, a character outside letters, digits
 * and hyphens): the website would exist, count toward `sites.max`, and never be reached. Its own type so no broad `catch (RuntimeException)` swallows
 * it; the caller explains it in plain words.
 */
final class HostnameInvalidException extends DomainException
{
    public function __construct(public readonly string $hostname)
    {
        parent::__construct("The hostname \"{$hostname}\" cannot identify a website.");
    }
}
