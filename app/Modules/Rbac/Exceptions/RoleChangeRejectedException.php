<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Exceptions;

use DomainException;

/**
 * A custom-role change refused for a BUSINESS reason the caller should explain in plain words —
 * as opposed to AuthorizationException, which means "you are not allowed to". Its own type so no
 * broad `catch (RuntimeException)` swallows it (a QueryException is one too). The message IS the
 * reason key: for a custom role one of CUSTOM_ROLE_REASONS, each with its sentence at
 * `rbac.role_change.<reason>` in every language file; for an owner recovery (RecoverSiteOwner):
 * not_operator · reason_required · operator · no_owner_role · already_owner.
 */
final class RoleChangeRejectedException extends DomainException
{
    /** The reasons a custom-role write (create, edit, delete, restore) is refused for. */
    public const array CUSTOM_ROLE_REASONS = [
        'custom_roles_disabled', 'name_invalid', 'name_reserved', 'name_taken', 'level_invalid',
        'in_use', 'outside_category_scope', 'too_many', 'changed_elsewhere', 'gone', 'restore_name_taken',
        'restore_in_use',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
