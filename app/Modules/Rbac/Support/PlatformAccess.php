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
 *
 * isSuperAdmin() is memoized per user-id because Gate::before fires on EVERY
 * authorization check (including AdminNav per-section).  The memo is stored on this
 * singleton and is reset by FlushRbacTeamContext at RequestTerminated / TaskTerminated /
 * TickTerminated, so it never leaks a stale verdict across requests under Octane.
 */
final class PlatformAccess
{
    /** @var array<int, bool> */
    private array $superAdminMemo = [];

    public function __construct(private readonly TeamContext $context) {}

    public function isSuperAdmin(User $user): bool
    {
        $id = $user->getKey();

        if (! is_int($id)) {
            // Unsaved / non-integer PK: skip memo, query directly.
            return $this->hasAnyPlatformRole($user, ['super_admin']);
        }

        if (! array_key_exists($id, $this->superAdminMemo)) {
            $this->superAdminMemo[$id] = $this->hasAnyPlatformRole($user, ['super_admin']);
        }

        return $this->superAdminMemo[$id];
    }

    /**
     * Reset the per-request memo. Called by FlushRbacTeamContext on every Octane
     * operation termination so a role change in one request is never hidden from
     * subsequent requests served by the same worker.
     */
    public function forgetMemo(): void
    {
        $this->superAdminMemo = [];
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
     * Is the user a Bảo Sơn operator — does it hold ANY platform role? The single predicate
     * that gates access to the platform plane, shared by the login gate and EnsurePlatformAccess
     * so authentication and the middleware apply the exact same rule.
     */
    public function isOperator(User $user): bool
    {
        return $this->hasAnyPlatformRole($user, PermissionRegistry::platformRoleNames());
    }

    /**
     * Does the user hold this permission at PLATFORM scope (team 0), regardless of the active
     * tenant? For platform-only gates whose permission never lives in a tenant team.
     */
    public function can(User $user, string $permission): bool
    {
        return $this->onPlatform(static fn (): bool => $user->hasPermissionTo($permission));
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
