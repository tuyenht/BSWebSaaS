<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use DomainException;

/**
 * A change to what a website or a plan is granted, refused for a BUSINESS reason the caller should
 * explain in plain words. Its own type so no broad `catch (RuntimeException)` swallows it. The
 * message IS the reason key, one of: unknown_feature · wrong_kind · customer_wide.
 */
final class FeatureChangeRejectedException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
