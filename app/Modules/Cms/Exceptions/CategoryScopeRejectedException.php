<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;

/**
 * A change to a member's category limits refused for a BUSINESS reason the caller should explain in
 * plain words — as opposed to AuthorizationException, which means "you are not allowed to". Its own
 * type so no broad `catch (RuntimeException)` swallows it. The message IS the reason key, one of:
 * category_scopes_disabled · not_member · target_exempt · taxonomy_invalid · outside_own_scope.
 */
final class CategoryScopeRejectedException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
