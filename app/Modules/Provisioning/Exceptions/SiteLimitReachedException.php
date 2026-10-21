<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use DomainException;

/**
 * A new website refused because its customer already has as many as their plan allows
 * (`sites.max`). Its own type so no broad `catch (RuntimeException)` swallows it; the caller
 * explains it in plain words with the numbers it carries.
 */
final class SiteLimitReachedException extends DomainException
{
    public function __construct(public readonly int $count, public readonly int $limit)
    {
        parent::__construct("The customer has {$count} of {$limit} websites allowed by their plan.");
    }
}
