<?php

namespace App\Modules\Rbac\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use Closure;

/**
 * Platform-scope authorization. Platform roles live at the sentinel team (site_id 0),
 * so checking them requires switching the team context regardless of the active
 * tenant — done here with a snapshot/restore on the shared TeamContext that is
 * leak-safe under Octane.
 */
final class PlatformAccess
{
    public function __construct(private readonly TeamContext $context) {}

    public function isSuperAdmin(User $user): bool
    {
        // A fresh relationship query (not $user->hasRole, which would reuse a roles
        // relation already eager-loaded at the tenant team by spatie's own gate check).
        return $this->onPlatform(static fn (): bool => $user->roles()->where('name', 'super_admin')->exists());
    }

    /**
     * Run a callback with the team context forced to platform scope, then restore.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function onPlatform(Closure $callback): mixed
    {
        $snapshot = $this->context->snapshot();
        $this->context->force(PermissionRegistry::PLATFORM_TEAM_ID);

        try {
            return $callback();
        } finally {
            $this->context->restore($snapshot);
        }
    }
}
