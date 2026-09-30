<?php

namespace App\Modules\Admin\Livewire\Users;

use App\Modules\Admin\Notifications\MemberInvitationNotification;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Actions\AssignRole;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Actions\RevokeRole;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\IssuedInvitation;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Site members + their roles. Invite an address with a role (InviteMember — the holder accepts
 * from the mailed link), change a member's role, or remove them. Every grant routes through the
 * guarded InviteMember / AssignRole / RevokeRole actions, so the no-escalation hierarchy invariant
 * holds (grant/revoke strictly below your own level, within your own permissions); the role
 * <select> only ever offers roles the actor may grant. Gated by `users.manage` (owner/admin,
 * super_admin via Gate::before); inviting also needs `users.invite`.
 *
 * `users` is GLOBAL identity (D6 — no RLS), so nothing below the app stops a lookup by key from
 * reaching another tenant's account. Change/remove therefore resolve their target through
 * {@see siteMember()} — by ulid, and only among this site's members — never by a client-supplied
 * bigint (§2 #3/#4): otherwise any owner could pull an arbitrary account into their site and read
 * its name + email off this table.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    public string $inviteEmail = '';

    public string $inviteRole = '';

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so re-check
        // the members-management permission on every request (mount + every action).
        abort_unless(Gate::allows('users.manage'), 403);
    }

    /**
     * Invite an address with a role (design.md §15.3). The reply is the same sentence whether or
     * not the address holds an account anywhere on the platform — it used to answer "no account
     * with this email", which let any site admin probe the platform's user list. The mail goes to
     * the address; its holder signs in or creates the account from the link.
     */
    public function addMember(): void
    {
        $this->validate([
            'inviteEmail' => 'required|email|max:254',
            'inviteRole' => 'required|string',
        ]);

        $actor = auth()->user();
        if (! $actor instanceof User) {
            return;
        }

        $role = $this->siteRole($this->inviteRole);
        if (! $role instanceof Role) {
            $this->addError('inviteRole', __('admin.role_not_found'));

            return;
        }

        try {
            $issued = app(InviteMember::class)->handle($actor, $this->inviteEmail, [$role]);
        } catch (AuthorizationException) {
            $this->addError('inviteRole', __('admin.role_change_denied'));

            return;
        } catch (InvitationRejectedException $e) {
            $this->addError('inviteEmail', __('rbac.invitation.error_'.$e->reason));

            return;
        }

        $this->mailInvitation($issued, $actor);

        $this->reset('inviteEmail', 'inviteRole');
        session()->flash('status', __('rbac.invitation.sent', [
            'email' => $issued->invitation->email,
            'days' => InviteMember::VALID_DAYS,
        ]));
    }

    public function changeRole(string $memberUlid, string $roleName): void
    {
        $actor = auth()->user();
        $target = $this->siteMember($memberUlid);
        $role = $this->siteRole($roleName);

        if (! $target instanceof User) {
            session()->flash('error', __('admin.member_not_found'));

            return;
        }

        if (! $actor instanceof User || ! $role instanceof Role) {
            return;
        }

        try {
            // Atomic: assign first (so the member never drops below membership), then strip the
            // other roles so the selection is authoritative. A failed revoke (e.g. a role above
            // the actor) rolls the assign back instead of leaving a mixed two-role state.
            DB::transaction(function () use ($actor, $target, $role): void {
                app(AssignRole::class)->handle($actor, $target, $role);

                foreach ($target->roles()->get() as $existing) {
                    if ($existing instanceof Role && $existing->getKey() !== $role->getKey()) {
                        app(RevokeRole::class)->handle($actor, $target, $existing);
                    }
                }
            });
        } catch (AuthorizationException) {
            session()->flash('error', app(SupportSession::class)->active() ? __('admin.support.locked') : __('admin.role_change_denied'));

            return;
        }

        session()->flash('status', __('admin.saved'));
    }

    public function removeMember(string $memberUlid): void
    {
        $actor = auth()->user();
        $target = $this->siteMember($memberUlid);

        if (! $target instanceof User) {
            session()->flash('error', __('admin.member_not_found'));

            return;
        }

        if (! $actor instanceof User) {
            return;
        }

        try {
            // Atomic: all of the member's roles are revoked or none — a role above the actor
            // (which can't be stripped) rolls the whole removal back instead of half-removing.
            DB::transaction(function () use ($actor, $target): void {
                foreach ($target->roles()->get() as $role) {
                    if ($role instanceof Role) {
                        app(RevokeRole::class)->handle($actor, $target, $role);
                    }
                }
            });
        } catch (AuthorizationException) {
            session()->flash('error', app(SupportSession::class)->active() ? __('admin.support.locked') : __('admin.member_remove_denied'));

            return;
        }

        session()->flash('status', __('admin.member_removed'));
    }

    public function render(): View
    {
        $actor = auth()->user();
        $siteId = app(CurrentSite::class)->id();

        $members = User::query()
            ->select('users.id', 'users.ulid', 'users.name', 'users.email')
            ->join('model_has_roles as mhr', 'mhr.model_id', '=', 'users.id')
            ->where('mhr.model_type', User::class)
            ->where('mhr.site_id', $siteId)
            ->with('roles')
            ->distinct()
            ->orderBy('users.name')
            ->get();

        $assignableRoles = $actor instanceof User
            ? app(RoleHierarchyGuard::class)->assignableRoles($actor)
            : collect();

        $view = view('admin::livewire.users.index', [
            'members' => $members,
            'assignableRoles' => $assignableRoles,
        ]);
        $view->title(__('admin.users'));

        return $view;
    }

    /** A member of the CURRENT site (holds a role in its team), addressed by public ulid. */
    private function siteMember(string $ulid): ?User
    {
        return User::query()
            ->where('ulid', $ulid)
            ->whereExists(fn (Builder $membership): Builder => $membership->selectRaw('1')
                ->from('model_has_roles')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', User::class)
                ->where('model_has_roles.site_id', app(CurrentSite::class)->id()))
            ->first();
    }

    private function siteRole(string $name): ?Role
    {
        return Role::query()
            ->where('site_id', app(CurrentSite::class)->id())
            ->where('name', $name)
            ->first();
    }

    /**
     * Queue the invitation mail. Everything is composed here, in the request: the worker has no
     * host to build the link from and no site context to read names in. Same mail for every
     * address — the invitation page decides whether to sign in or create the account.
     */
    private function mailInvitation(IssuedInvitation $issued, User $actor): void
    {
        $invitation = $issued->invitation;
        $siteName = Site::query()->whereKey(app(CurrentSite::class)->id())->value('name');
        $expiresAt = app(SiteTime::class)->forDisplay($invitation->expires_at);

        $notification = (new MemberInvitationNotification(
            acceptUrl: route('admin.invitations.accept', ['token' => $issued->token]),
            siteName: is_string($siteName) && $siteName !== '' ? $siteName : request()->getHost(),
            inviterName: $actor->name,
            roles: array_values($invitation->roles()->get()->map(fn (Role $role): array => ['name' => $role->name, 'system' => $role->is_system])->all()),
            personalMessage: $invitation->message,
            expiresOn: $expiresAt?->format('d/m/Y H:i') ?? '',
        ))->forCurrentSite();

        Notification::route('mail', $invitation->email)
            ->notify($notification->locale(app(SiteLocale::class)->default()));
    }
}
