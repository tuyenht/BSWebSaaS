<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use DomainException;

/**
 * A change to a site's domains refused for a BUSINESS reason the caller should explain in plain
 * words. Its own type so no broad `catch (RuntimeException)` swallows it. The message IS the reason
 * key, one of: invalid · reserved · already_attached · limit_reached · not_found · platform_subdomain.
 */
final class DomainChangeRejectedException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
