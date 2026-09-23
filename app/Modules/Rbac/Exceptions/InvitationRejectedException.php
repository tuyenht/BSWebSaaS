<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Exceptions;

use DomainException;

/**
 * An invitation step refused for a BUSINESS reason the caller should explain in plain words — as
 * opposed to AuthorizationException ("you are not allowed to"). Its own type so no broad
 * `catch (RuntimeException)` swallows it. The message IS the reason key, one of:
 *  - issuing:   no_roles · already_member · daily_limit · resend_too_soon
 *  - accepting: invalid (unknown, revoked or already used) · expired · wrong_account
 *               (signed in as a different address) · account_exists (a new-account answer for
 *               an address that has an account — it must sign in) · no_longer_valid (the inviter
 *               can no longer grant those roles, or none are left)
 */
final class InvitationRejectedException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
