<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Exceptions;

use DomainException;

/**
 * A custom-role change refused for a BUSINESS reason the caller should explain in plain words —
 * as opposed to AuthorizationException, which means "you are not allowed to". Its own type so no
 * broad `catch (RuntimeException)` swallows it (a QueryException is one too). The message IS the
 * reason key, one of: custom_roles_disabled · name_invalid · name_reserved · name_taken ·
 * level_invalid · in_use.
 */
final class RoleChangeRejectedException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
