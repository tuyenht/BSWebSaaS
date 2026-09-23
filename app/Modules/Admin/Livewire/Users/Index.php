<?php

namespace App\Modules\Admin\Livewire\Users;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\AssignRole;
use App\Modules\Rbac\Actions\RevokeRole;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Site members + their roles. Add an existing account to this site with a role, change a
 * member's role, or remove them. Every mutation routes through the guarded AssignRole /
 * RevokeRole actions, so the no-escalation hierarchy invariant holds (grant/revoke strictly
 * below your own level, within your own permissions); the role <select> only ever offers
 * roles the actor may grant. Gated by `users.manage` (owner/admin, super_admin via Gate::before).
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

    public function addMember(): void
    {
        $this->validate([
            'inviteEmail' => 'required|email',
            'inviteRole' => 'required|string',
        ]);

        $actor = auth()->user();
        if (! $actor instanceof User) {
            return;
        }

        $target = User::query()->firstWhere('email', $this->inviteEmail);
        if (! $target instanceof User) {
            $this->addError('inviteEmail', __('admin.user_not_found'));

            return;
        }

        $role = $this->siteRole($this->inviteRole);
        if (! $role instanceof Role) {
            $this->addError('inviteRole', __('admin.role_not_found'));

            return;
        }

        try {
            app(AssignRole::class)->handle($actor, $target, $role);
        } catch (AuthorizationException) {
            $this->addError('inviteRole', __('admin.role_change_denied'));

            return;
        }

        $this->reset('inviteEmail', 'inviteRole');
        session()->flash('status', __('admin.member_added'));
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
            session()->flash('error', __('admin.role_change_denied'));

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
            session()->flash('error', __('admin.member_remove_denied'));

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
}
