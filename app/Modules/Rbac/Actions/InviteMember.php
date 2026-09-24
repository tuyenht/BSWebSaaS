<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\IssuedInvitation;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Invite an email address to the current site with one or more roles — or refresh the open
 * invitation it already has (new link, new expiry, the roles given now). design.md §15.3.
 *
 * What the inviter learns must not depend on whether the address holds an account anywhere on
 * the platform: the only refusals are about THIS site (already a member here — visible in the
 * member list anyway) or the inviter's own limits. Who the address belongs to is settled by its
 * holder when they open the link.
 *
 *  - `users.invite` + the no-escalation rule: every role strictly below the inviter's level and
 *    within the permissions they hold (RoleHierarchyGuard) — checked again when it is accepted.
 *  - Invitation MAIL per site is capped at DAILY_LIMIT per rolling 24 hours — every mail counts, a
 *    re-sent invitation included (one `rbac.invitation.sent` audit row per mail). One address gets
 *    at most ADDRESS_DAILY_LIMIT of them from this site in that window, and one invitation is not
 *    re-mailed within RESEND_COOLDOWN_SECONDS (a double click must not spend that allowance), so the
 *    form cannot be used to flood an inbox under the platform's shared sender. The address limit is
 *    per site on purpose: counting across sites would tell this site that another one had invited
 *    the same person.
 *  - Invites on one site are serialized by a transaction-scoped advisory lock (lockKey): the daily
 *    cap is counted and then written, so two invites for different addresses at the same moment
 *    cannot both pass it — and the pending-invitation unique index is never hit by a race.
 *
 * Returns the plain token for the caller that owns mail delivery; only its hash is stored.
 */
final readonly class InviteMember
{
    /** Invitation mails a site may send per rolling 24 hours (owner decision 24/09). */
    public const int DAILY_LIMIT = 50;

    /** Invitation mails one address may get from one site per rolling 24 hours (owner decision 24/09). */
    public const int ADDRESS_DAILY_LIMIT = 3;

    /** How long an invitation stays open (owner decision 24/09). */
    public const int VALID_DAYS = 7;

    /** Minimum gap before the same invitation is mailed again. */
    public const int RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        private RoleHierarchyGuard $guard,
        private CurrentSite $current,
    ) {}

    /**
     * @param  list<Role>  $roles
     *
     * @throws AuthorizationException the actor may not invite, or a role is not theirs to grant
     * @throws InvitationRejectedException no_roles · already_member · resend_too_soon · address_daily_limit · daily_limit
     */
    public function handle(User $actor, string $email, array $roles, ?string $message = null): IssuedInvitation
    {
        Gate::forUser($actor)->authorize('users.invite');

        if ($roles === []) {
            throw new InvitationRejectedException('no_roles');
        }

        foreach ($roles as $role) {
            $this->guard->assertCanGrant($actor, $role);
        }

        $email = Str::lower(trim($email));
        $siteId = $this->current->id();
        $message = $message !== null && trim($message) !== '' ? Str::limit(trim($message), 500, '') : null;

        if ($this->isMember($email)) {
            throw new InvitationRejectedException('already_member');
        }

        $token = Str::random(64);

        return DB::transaction(function () use ($actor, $email, $roles, $message, $siteId, $token): IssuedInvitation {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [self::lockKey($siteId)]);

            $invitation = Invitation::query()
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('email', $email)
                ->first();

            if ($invitation !== null && $invitation->sent_at->isAfter(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
                throw new InvitationRejectedException('resend_too_soon');
            }

            if ($this->mailsSentToday($siteId, $email) >= self::ADDRESS_DAILY_LIMIT) {
                throw new InvitationRejectedException('address_daily_limit');
            }

            if ($this->mailsSentToday($siteId) >= self::DAILY_LIMIT) {
                throw new InvitationRejectedException('daily_limit');
            }

            $refreshed = $invitation !== null;
            $invitation ??= new Invitation(['email' => $email]);
            $invitation->forceFill([
                'token_hash' => Invitation::hashToken($token),
                'message' => $message,
                'invited_by' => $actor->getKey(),
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'sent_at' => now(),
            ])->save();

            $invitation->roles()->syncWithPivotValues(
                array_map(static fn (Role $role): mixed => $role->getKey(), $roles),
                ['site_id' => $siteId],
            );

            Audit::record('rbac.invitation.sent', [
                'auditable_type' => Invitation::class,
                'auditable_id' => $invitation->getKey(),
                'meta' => [
                    'email' => $email,
                    'roles' => array_map(static fn (Role $role): string => $role->name, $roles),
                    'refreshed' => $refreshed,
                ],
            ]);

            return new IssuedInvitation($invitation, $token);
        });
    }

    /** The advisory-lock key every invitation write on $siteId waits on. */
    public static function lockKey(int $siteId): string
    {
        return "invitation:{$siteId}";
    }

    /**
     * Invitation mails this site sent in the last 24 hours — to $email only, when given — new and
     * re-sent alike: counting open invitations instead would let one address be re-mailed every
     * cooldown, all day. The audit trail holds one row per mail (not RLS-scoped, hence the explicit
     * site filter), read through its (site_id, created_at) index.
     */
    private function mailsSentToday(int $siteId, ?string $email = null): int
    {
        return AuditLog::query()
            ->where('site_id', $siteId)
            ->where('action', 'rbac.invitation.sent')
            ->where('created_at', '>=', now()->subDay())
            ->when($email !== null, fn (Builder $mails): Builder => $mails->where('meta->email', $email))
            ->count();
    }

    /** Whether the address already holds a role on the current site (team-scoped). */
    private function isMember(string $email): bool
    {
        $user = User::query()->where('email', $email)->first();

        return $user instanceof User && $user->roles()->exists();
    }
}
