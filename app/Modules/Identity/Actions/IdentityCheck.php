<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

/** What ConfirmIdentity found: the member proved it is them, or why not. */
enum IdentityCheck: string
{
    case Confirmed = 'confirmed';

    /** Nothing typed (or not 6 digits for a code): nothing is counted against the member. */
    case Missing = 'missing';

    /** A wrong password or code, or a code already used: one try is counted. */
    case Wrong = 'wrong';

    /** Too many wrong tries: nothing is checked until the wait is over (ConfirmIdentity::retryIn). */
    case Throttled = 'throttled';

    /** The account signs in only through Google or Facebook: it has no password to type. */
    case NoPassword = 'no_password';

    /** The account has no authenticator app set up: it has no code to type. */
    case NoTwoFactor = 'no_two_factor';

    public function confirmed(): bool
    {
        return $this === self::Confirmed;
    }
}
