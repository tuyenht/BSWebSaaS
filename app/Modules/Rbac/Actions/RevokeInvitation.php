<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Withdraw an invitation that has not been accepted: its link stops working at once. Gated by
 * `users.invite`, the same permission that issues it. An accepted invitation is history, not
 * something to withdraw — removing that member is a role change.
 */
final readonly class RevokeInvitation
{
    /**
     * @throws AuthorizationException
     * @throws InvitationRejectedException invalid (already accepted or revoked)
     */
    public function handle(User $actor, Invitation $invitation): void
    {
        Gate::forUser($actor)->authorize('users.invite');

        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw new InvitationRejectedException('invalid');
        }

        $invitation->forceFill(['revoked_at' => now()])->save();

        Audit::record('rbac.invitation.revoked', [
            'auditable_type' => Invitation::class,
            'auditable_id' => $invitation->getKey(),
            'meta' => ['email' => $invitation->email],
        ]);
    }
}
