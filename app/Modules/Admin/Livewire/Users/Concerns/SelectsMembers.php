<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Users\Concerns;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Actions\RevokeRole;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ticking members and acting on all of them at once: "Đổi nhóm (N)" gives every ticked member you
 * may change exactly the roles chosen in the panel, "Xóa khỏi website (N)" removes them after one
 * confirmation. Members you may not change (yourself, anyone at or above your level) stay ticked
 * but are skipped, and the bar names them. Each action runs in one transaction through AssignRole /
 * RevokeRole, so a refused step changes nobody.
 *
 * The ticks are ulids that come back from the browser: they are resolved among this site's members
 * on every read, and anything else is dropped.
 */
trait SelectsMembers
{
    /** At most this many members ticked at once (five pages of the largest page size). */
    public const int PICK_MAX = 250;

    /** @var list<string> the ticked members (ulids) */
    public array $picked = [];

    /** The roles panel is open for the ticked members. */
    public bool $panelBulk = false;

    /** The removal question is about the ticked members. */
    public bool $removeBulk = false;

    /**
     * Tick the given members, or untick them all when every one is ticked already (the header box).
     *
     * @param  list<string>  $ulids
     */
    public function togglePick(array $ulids): void
    {
        $this->ensureOpen();
        $ulids = array_values(array_unique(array_map('strtoupper', array_filter(Cast::strings($ulids), fn (string $u): bool => Str::isUlid($u)))));
        $ulids = array_slice($ulids, 0, self::PICK_MAX);
        $allIn = $ulids !== [] && array_diff($ulids, $this->picked) === [];
        $next = $allIn ? array_diff($this->picked, $ulids) : [...$this->picked, ...$ulids];
        $this->picked = array_slice(array_values(array_unique($next)), 0, self::PICK_MAX);
    }

    /** "Bỏ chọn". */
    public function clearPicked(): void
    {
        $this->ensureOpen();
        $this->picked = [];
    }

    /** "Đổi nhóm (N)": the roles panel for every ticked member you may change; nothing is ticked in it yet. */
    public function openBulkPanel(): void
    {
        $this->ensureOpen();
        if ($this->refusedWhileSupport()) {
            return;
        }
        if ($this->pickedTouchable()->isEmpty()) {
            $this->say(__('admin.members.cannot_touch'), 'error');

            return;
        }
        $this->panelFor = '';
        $this->panelBulk = true;
        $this->panelRoles = [];
        $this->panelError = '';
    }

    /** "Xóa khỏi website (N)": ask once for every ticked member you may change. */
    public function askRemovePicked(): void
    {
        $this->ensureOpen();
        if ($this->refusedWhileSupport()) {
            return;
        }
        if ($this->pickedTouchable()->isEmpty()) {
            $this->say(__('admin.members.cannot_touch'), 'error');

            return;
        }
        $this->removeFor = '';
        $this->removeBulk = true;
        $this->confirmOpen = true;
    }

    /**
     * The ticked members of this site, with their roles; ticks that are no longer members are dropped.
     *
     * @return Collection<int, User>
     */
    private function pickedMembers(): Collection
    {
        $this->picked = array_slice(array_values(array_unique(array_filter(Cast::strings($this->picked), fn (string $u): bool => Str::isUlid($u)))), 0, self::PICK_MAX);
        if ($this->picked === []) {
            return new Collection;
        }
        $members = User::query()
            ->whereIn('ulid', $this->picked)
            ->whereExists(fn (Builder $membership): Builder => $membership->selectRaw('1')
                ->from('model_has_roles')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', User::class)
                ->where('model_has_roles.site_id', app(CurrentSite::class)->id()))
            ->orderBy('name')
            ->orderBy('id')
            ->with('roles')
            ->get(['id', 'ulid', 'name', 'email']);
        $this->picked = array_values(array_intersect($this->picked, Cast::strings($members->pluck('ulid')->all())));

        return $members;
    }

    /**
     * The ticked members the actor may change: not themselves, every role strictly below their level.
     *
     * @param  Collection<int, User>|null  $members
     * @return Collection<int, User>
     */
    private function pickedTouchable(?Collection $members = null): Collection
    {
        $actor = $this->actor();
        $ceiling = app(RoleHierarchyGuard::class)->levelCeiling($actor);

        return ($members ?? $this->pickedMembers())
            ->filter(fn (User $u): bool => $u->id !== $actor->id && Cast::int($u->roles->max('level')) < $ceiling)
            ->values();
    }

    /**
     * Give every member you may change among the ticked ones exactly $roles, in one transaction.
     *
     * @param  Collection<int, Role>  $roles
     */
    private function saveBulkRoles(Collection $roles): void
    {
        $targets = $this->pickedTouchable();
        if ($targets->isEmpty()) {
            $this->closePanel();
            $this->say(__('admin.members.cannot_touch'), 'error');

            return;
        }
        try {
            DB::transaction(function () use ($targets, $roles): void {
                foreach ($targets as $target) {
                    $this->giveRoles($target, $roles);
                }
            });
        } catch (AuthorizationException) {
            $this->panelError = app(SupportSession::class)->active() ? __('admin.support.locked') : __('admin.role_change_denied');

            return;
        }
        $this->closePanel();
        $this->picked = [];
        $this->say(__('admin.members.saved_roles_many', ['n' => $targets->count()]));
    }

    /** Remove every member you may change among the ticked ones: all their roles on this site, in one transaction. */
    private function removePicked(): void
    {
        $targets = $this->pickedTouchable();
        if ($targets->isEmpty()) {
            $this->say(__('admin.members.cannot_touch'), 'error');

            return;
        }
        $actor = $this->actor();
        try {
            DB::transaction(function () use ($actor, $targets): void {
                foreach ($targets as $target) {
                    foreach ($target->roles()->get() as $role) {
                        if ($role instanceof Role) {
                            app(RevokeRole::class)->handle($actor, $target, $role);
                        }
                    }
                }
            });
        } catch (AuthorizationException) {
            $this->say(app(SupportSession::class)->active() ? __('admin.support.locked') : __('admin.member_remove_denied'), 'error');

            return;
        }
        $this->picked = [];
        $this->undoRoles = null;
        $this->say(__('admin.members.removed_many', ['n' => $targets->count()]));
    }
}
