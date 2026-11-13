<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Users\Concerns;

use App\Modules\Admin\Support\Members\InvitationDirectory;
use App\Modules\Admin\Support\Members\MemberDirectory;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Actions\RevokeInvitation;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * The "Lời mời" tab of the members screen: the site's invitations by state (waiting, expired,
 * withdrawn, accepted), and what can be done with one — mail it again (a new link, a new expiry),
 * withdraw it, invite the address again after a withdrawal, or open the member who accepted it.
 *
 * Who may do what is never decided here: a new mail goes through InviteMember (`users.invite`, every
 * role strictly below the sender's level and within their permissions, the site's daily cap), a
 * withdrawal through RevokeInvitation, and both refuse while support staff are signed in as a member.
 * The screen holds a withdrawal to the same level rule as a new mail: an invitation that carries a
 * role you could not grant is not yours to withdraw either. An invitation is addressed by its ulid
 * and read through the tenant scope, so another site's invitation is never found.
 */
trait ManagesInvitations
{
    /** '' for the members, 'loi-moi' for the invitations. */
    #[Url(as: 'xem', except: '')]
    public string $tab = '';

    /** The invitations' state group: pending, expired, revoked or accepted. */
    #[Url(as: 'tt', except: 'pending')]
    public string $state = 'pending';

    /** The invitation the withdrawal question is about (ulid). */
    public string $revokeFor = '';

    public bool $revokeOpen = false;

    /** "Gửi lại": mail a waiting or expired invitation again — a new link and a new expiry; the old link stops working. */
    public function resendInvitation(string $ulid): void
    {
        $this->ensureInviter();
        if ($this->refusedWhileSupport()) {
            return;
        }
        $invitation = $this->liveInvitation($ulid);
        if (! $invitation instanceof Invitation) {
            return;
        }
        // the roles in full: the grant check reads each one's guard and permissions
        $roles = $invitation->roles()->get()->all();
        if ($roles === []) {
            // every role it carried was deleted since: nothing is left to invite to
            $this->say(__('admin.members.inv_gone'), 'error');

            return;
        }
        try {
            $issued = app(InviteMember::class)->handle($this->actor(), $invitation->email, array_values($roles), $invitation->message);
            $siteId = app(CurrentSite::class)->id();
            $this->mailInvitation($issued, $this->actor(), $this->siteName($siteId), $this->mailRoles($roles));
        } catch (AuthorizationException) {
            $this->say(__('admin.role_change_denied'), 'error');

            return;
        } catch (InvitationRejectedException $e) {
            $this->say(__('rbac.invitation.error_'.$e->reason), 'error');

            return;
        }
        $this->say(__('admin.members.inv_resent', ['email' => $invitation->email, 'days' => InviteMember::VALID_DAYS]));
    }

    /** "Hủy lời mời…": ask first — the link stops working at once and cannot be brought back. */
    public function askRevoke(string $ulid): void
    {
        $this->ensureInviter();
        if ($this->refusedWhileSupport()) {
            return;
        }
        $invitation = $this->liveInvitation($ulid);
        if ($invitation instanceof Invitation) {
            $this->revokeFor = $invitation->ulid;
            $this->revokeOpen = true;
        }
    }

    public function cancelRevoke(): void
    {
        $this->revokeOpen = false;
        $this->revokeFor = '';
    }

    public function confirmRevoke(): void
    {
        $this->ensureInviter();
        $ulid = $this->revokeFor;
        $this->cancelRevoke();
        if ($this->refusedWhileSupport()) {
            return;
        }
        $invitation = $this->liveInvitation($ulid);
        if (! $invitation instanceof Invitation) {
            return;
        }
        try {
            app(RevokeInvitation::class)->handle($this->actor(), $invitation);
        } catch (AuthorizationException) {
            $this->say(app(SupportSession::class)->active() ? __('admin.support.locked') : __('admin.role_change_denied'), 'error');

            return;
        } catch (InvitationRejectedException) {
            $this->say(__('admin.members.inv_gone'), 'error');

            return;
        }
        $this->say(__('admin.members.inv_revoked', ['email' => $invitation->email]));
    }

    /** "Mời lại" on a withdrawn invitation: the invite dialog, filled with its address, the roles you may grant and its message. */
    public function reinvite(string $ulid): void
    {
        $this->ensureInviter();
        if ($this->refusedWhileSupport()) {
            return;
        }
        $invitation = $this->siteInvitation($ulid);
        if (! $invitation instanceof Invitation || InvitationDirectory::stateOf($invitation) !== 'revoked') {
            $this->say(__('admin.members.inv_gone'), 'error');

            return;
        }
        // the same daily share as "Mời thành viên": the dialog would only refuse at "Gửi"
        if ($this->siteFull()) {
            $this->say(__('admin.members.inv_full', ['max' => InviteMember::DAILY_LIMIT]), 'error');

            return;
        }
        $grantable = Cast::strings(app(RoleHierarchyGuard::class)->assignableRoles($this->actor())->pluck('name')->all());
        $this->resetErrorBag();
        $this->inviteResults = [];
        $this->inviteTried = false;
        $this->inviteEmail = $invitation->email;
        $this->inviteRoles = array_values(array_intersect(Cast::strings($invitation->roles->pluck('name')->all()), $grantable));
        $this->inviteMessage = (string) $invitation->message;
        $this->inviteOpen = true;
    }

    /** "Xem thành viên" on an accepted invitation: the members tab, searching for its address. */
    public function viewMember(string $ulid): void
    {
        $this->ensureOpen();
        $invitation = $this->siteInvitation($ulid);
        if (! $invitation instanceof Invitation) {
            return;
        }
        $this->tab = '';
        $this->search = mb_substr($invitation->email, 0, MemberDirectory::SEARCH_MAX);
        $this->groups = [];
        $this->after = $this->before = '';
    }

    /**
     * Whether the actor may mail or withdraw $invitation: every role it carries is one they may grant.
     *
     * @param  list<string>  $grantable  role names the actor may grant
     */
    public static function invitationGrantable(Invitation $invitation, array $grantable): bool
    {
        return $invitation->roles->every(fn (Role $role): bool => in_array($role->name, $grantable, true));
    }

    /** The invitation write methods: the member may manage members AND send invitations. */
    private function ensureInviter(): void
    {
        $this->ensureOpen();
        abort_unless(Gate::allows('users.invite'), 403);
    }

    /** An invitation of the CURRENT site, addressed by public ulid (the tenant scope and the explicit site keep it to this site). */
    private function siteInvitation(string $ulid): ?Invitation
    {
        if (! Str::isUlid($ulid)) {
            return null;
        }

        return Invitation::query()
            ->where('site_id', app(CurrentSite::class)->id())
            ->where('ulid', strtoupper($ulid))
            ->with('roles:id,name,level,is_system')
            ->first();
    }

    /**
     * A waiting or expired invitation the actor may act on, or null after saying why not.
     */
    private function liveInvitation(string $ulid): ?Invitation
    {
        $invitation = $this->siteInvitation($ulid);
        if (! $invitation instanceof Invitation || ! in_array(InvitationDirectory::stateOf($invitation), ['pending', 'expired'], true)) {
            $this->say(__('admin.members.inv_gone'), 'error');

            return null;
        }
        $grantable = app(RoleHierarchyGuard::class)->assignableRoles($this->actor())->pluck('name')->all();
        if (! self::invitationGrantable($invitation, Cast::strings($grantable))) {
            $this->say(__('admin.members.inv_why_level_short'), 'error');

            return null;
        }

        return $invitation;
    }

    /**
     * The roles as the invitation mail names them.
     *
     * @param  array<int, Role>  $roles
     * @return list<array{name: string, system: bool}>
     */
    private function mailRoles(array $roles): array
    {
        return array_values(array_map(fn (Role $role): array => ['name' => $role->name, 'system' => (bool) $role->is_system], $roles));
    }
}
