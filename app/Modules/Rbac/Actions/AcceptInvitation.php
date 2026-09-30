<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Answer an invitation from its mailed link, on the invited site's own host (tenant context).
 * Two roads, chosen by whether the address holds an account — something only the recipient
 * learns, never the inviter:
 *
 *  - an existing account signs in the normal way (password + second factor), then joins. The
 *    link never touches that account's password — a leaked link must not be a way into it — and
 *    the signed-in account must BE the invited address;
 *  - an address with no account creates it right here (name + password). The token proves the
 *    person reads that mailbox, so the address starts verified.
 *
 * Joining grants the invitation's roles through AssignRole with the INVITER as actor: authority is
 * checked again now, so an inviter since demoted or removed cannot hand out roles they no longer
 * hold. Everything runs in one transaction with the invitation row locked — a double submit or two
 * tabs cannot both accept, and a refused grant leaves no half-created account behind.
 */
final readonly class AcceptInvitation
{
    public function __construct(private AssignRole $assign) {}

    /**
     * The open invitation a mailed token points at on the current site.
     *
     * @throws InvitationRejectedException invalid · expired
     */
    public function find(#[SensitiveParameter] string $token): Invitation
    {
        return $this->open(Invitation::query()->where('token_hash', Invitation::hashToken($token))->first());
    }

    /**
     * Join the site with the account that is signed in.
     *
     * @throws InvitationRejectedException invalid · expired · wrong_account · no_longer_valid
     */
    public function asSignedInUser(#[SensitiveParameter] string $token, User $user): Invitation
    {
        app(SupportSession::class)->forbid();   // membership never changes on someone's behalf

        return DB::transaction(function () use ($token, $user): Invitation {
            $invitation = $this->lockOpen($token);

            if (Str::lower($user->email) !== Str::lower($invitation->email)) {
                throw new InvitationRejectedException('wrong_account');
            }

            $this->join($invitation, $user);

            return $invitation;
        });
    }

    /**
     * Create the account for an address that has none, and join the site with it.
     *
     * @throws InvitationRejectedException invalid · expired · account_exists · no_longer_valid
     */
    public function withNewAccount(#[SensitiveParameter] string $token, string $name, #[SensitiveParameter] string $password): User
    {
        app(SupportSession::class)->forbid();   // membership never changes on someone's behalf

        return DB::transaction(function () use ($token, $name, $password): User {
            $invitation = $this->lockOpen($token);

            if (User::query()->where('email', $invitation->email)->exists()) {
                throw new InvitationRejectedException('account_exists');
            }

            try {
                $user = User::query()->create([
                    'name' => trim($name),
                    'email' => $invitation->email,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another invitation for the same address created the account a moment ago.
                throw new InvitationRejectedException('account_exists');
            }

            $this->join($invitation, $user);

            return $user;
        });
    }

    /** @throws InvitationRejectedException invalid · expired */
    private function lockOpen(string $token): Invitation
    {
        return $this->open(
            Invitation::query()->where('token_hash', Invitation::hashToken($token))->lockForUpdate()->first()
        );
    }

    /** @throws InvitationRejectedException invalid · expired */
    private function open(?Invitation $invitation): Invitation
    {
        if ($invitation === null || $invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw new InvitationRejectedException('invalid');
        }

        if ($invitation->isExpired()) {
            throw new InvitationRejectedException('expired');
        }

        return $invitation;
    }

    /** @throws InvitationRejectedException no_longer_valid */
    private function join(Invitation $invitation, User $user): void
    {
        $inviter = $invitation->inviter()->first();
        $roles = $invitation->roles()->get();

        if (! $inviter instanceof User || $roles->isEmpty()) {
            throw new InvitationRejectedException('no_longer_valid');
        }

        try {
            foreach ($roles as $role) {
                $this->assign->handle($inviter, $user, $role);
            }
        } catch (AuthorizationException) {
            throw new InvitationRejectedException('no_longer_valid');
        }

        $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->getKey()])->save();

        Audit::record('rbac.invitation.accepted', [
            'actor_id' => $user->getKey(),
            'actor_label' => $user->email,
            'auditable_type' => Invitation::class,
            'auditable_id' => $invitation->getKey(),
            'meta' => ['roles' => $roles->pluck('name')->all(), 'invited_by' => $inviter->getKey()],
        ]);
    }
}
