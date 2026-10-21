<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * A provision refused because a website is still proving it owns the hostname (an unverified
 * `domains` row — e.g. a name attached before its base became a platform base). A new site's
 * first domain is verified from birth, so starting one there would take the name from whoever is
 * proving it. Nothing is written; the caller explains it in plain words.
 */
final class HostnamePendingVerificationException extends DomainException
{
    public function __construct(public readonly string $hostname)
    {
        parent::__construct("The hostname {$hostname} is waiting on another website's verification.");
    }
}
