<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Events;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Invitation;

/**
 * An invitation was accepted: $member now holds its roles on the invitation's site. Rbac only
 * announces it; modules keeping per-invitation settings carry them over to the member (the post
 * categories the invitation limits them to, for one). Dispatched synchronously inside the accepting
 * transaction (not after commit), so the settings arrive with the membership, or neither does.
 */
final readonly class InvitationAccepted
{
    public function __construct(
        public Invitation $invitation,
        public User $member,
    ) {}
}
