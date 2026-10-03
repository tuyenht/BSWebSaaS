<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use DomainException;

/**
 * A change to the plan catalog, or to the plan a customer is on, refused for a BUSINESS reason the
 * caller should explain in plain words. Its own type so no broad `catch (RuntimeException)`
 * swallows it. The message IS the reason key, one of:
 * name_required · not_all_plans · plan_not_sold · reason_required.
 */
final class PlanChangeRejectedException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
