<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Providers;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Console\BootstrapSuperAdmin;
use App\Modules\Rbac\Console\SyncRbac;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Policies\RolePolicy;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class RbacServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // Shared team-context holder. spatie's registrar `new`s the resolver itself,
        // so the override state must live in this single shared instance.
        $this->app->singleton(TeamContext::class);

        // Singleton so the per-request isSuperAdmin memo (see PlatformAccess) survives
        // all Gate::before calls within one request. FlushRbacTeamContext resets the memo
        // at RequestTerminated / TaskTerminated / TickTerminated (Octane hygiene).
        $this->app->singleton(PlatformAccess::class);
    }

    public function boot(): void
    {
        Gate::policy(Role::class, RolePolicy::class);

        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            $platform = app(PlatformAccess::class);

            // A platform super_admin short-circuits every gate, in any tenant context.
            if ($platform->isSuperAdmin($user)) {
                return true;
            }

            // Catalog writes are platform-only (design.md §15.2 / §2): site_types is global/no-RLS,
            // so the app layer is the sole guard. Resolve the permission in platform scope (it lives
            // at team 0), independent of the active tenant. Deny → null so resolution ends in a denial.
            if ($ability === 'platform.site_types.manage') {
                return $platform->can($user, $ability) ? true : null;
            }

            return null;
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncRbac::class,
                BootstrapSuperAdmin::class,
            ]);
        }
    }
}
