<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Providers;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Console\BootstrapSuperAdmin;
use App\Modules\Rbac\Console\SyncRbac;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Listeners\SyncPermissionCache;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Policies\RolePolicy;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\VersionedPermissionRegistrar;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;
use Spatie\Permission\PermissionRegistrar;

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

        // The package's registrar, made to see role and permission changes other workers make
        // (VersionedPermissionRegistrar). An extender, because the package binds its own singleton
        // again while it boots, after this provider registers.
        $this->app->extend(PermissionRegistrar::class, fn (PermissionRegistrar $registrar, Application $app): PermissionRegistrar => $registrar instanceof VersionedPermissionRegistrar
            ? $registrar
            : new VersionedPermissionRegistrar($app->make('cache')));
    }

    public function boot(): void
    {
        Gate::policy(Role::class, RolePolicy::class);

        // Every long-lived worker starts each unit of work from the current permission catalog: an
        // Octane worker at each request, task and tick, a queue worker at each job. Registered here,
        // after Octane's own listeners from config/octane.php, so they run in the same order.
        foreach ([RequestReceived::class, TaskReceived::class, TickReceived::class, JobProcessing::class] as $event) {
            Event::listen($event, SyncPermissionCache::class);
        }

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
