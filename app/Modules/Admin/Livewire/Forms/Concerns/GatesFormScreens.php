<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Forms\Concerns;

use App\Modules\Forms\Models\Form;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The gate of the «Biểu mẫu» screens (list and editor), asked on every request because the
 * livewire/update endpoint skips the route: a member of the site, then the plan (the `contacts`
 * module), then FormPolicy (`forms.manage`). The two cards say which one stopped them.
 */
trait GatesFormScreens
{
    public function boot(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && ($user->roles->isNotEmpty() || app(PlatformAccess::class)->isSuperAdmin($user)), 403);
    }

    /** The plan or the no-permission card when one applies (nothing of a form is read then), else null. */
    private function lockedView(): ?View
    {
        if (! $this->entitled()) {
            return view('admin::livewire.forms.builder-plan', ['supportMail' => Cast::string(config('support.mailbox')) ?: Cast::string(config('mail.from.address'))]);
        }
        if (! Gate::allows('viewAny', Form::class)) {
            return view('admin::livewire.forms.builder-noperm', [
                'role' => RoleLabel::topOf($this->actingUser()),
                'manageRoles' => $this->manageRoles(),
                'canView' => Gate::allows('forms.view'),
            ]);
        }

        return null;
    }

    private function entitled(): bool
    {
        return in_array('contacts', app(Entitlement::class)->entitledModuleKeys(), true);
    }

    /** @return list<string> the names of the roles that may change forms */
    private function manageRoles(): array
    {
        $roles = Role::query()->where('site_id', app(CurrentSite::class)->id())
            ->where(fn ($q) => $q->where('name', 'owner')->orWhereHas('permissions', fn ($p) => $p->where('name', 'forms.manage')))
            ->select(['id', 'name', 'is_system', 'level'])->orderByDesc('level')->limit(20)->get();

        return array_values($roles->map(fn (Role $role): string => RoleLabel::for($role))->all());
    }

    private function actingUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
