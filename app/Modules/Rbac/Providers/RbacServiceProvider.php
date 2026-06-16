<?php

namespace App\Modules\Rbac\Providers;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Console\BootstrapSuperAdmin;
use App\Modules\Rbac\Console\SyncRbac;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class RbacServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared team-context holder. spatie's registrar `new`s the resolver itself,
        // so the override state must live in this single shared instance.
        $this->app->singleton(TeamContext::class);
    }

    public function boot(): void
    {
        // A platform super_admin short-circuits every gate, in any tenant context.
        Gate::before(function (mixed $user, string $ability): ?bool {
            if ($user instanceof User && app(PlatformAccess::class)->isSuperAdmin($user)) {
                return true;
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
