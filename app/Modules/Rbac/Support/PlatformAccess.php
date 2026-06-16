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
        return $this->hasAnyPlatformRole($user, ['super_admin']);
    }

    /**
     * Does the user hold any of these roles at platform scope? Uses a fresh
     * relationship query (not $user->hasRole, which would reuse a roles relation
     * already eager-loaded at a tenant team by spatie's own gate check).
     *
     * @param  list<string>  $roles
     */
    public function hasAnyPlatformRole(User $user, array $roles): bool
    {
        return $this->onPlatform(static fn (): bool => $user->roles()->whereIn('name', $roles)->exists());
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
