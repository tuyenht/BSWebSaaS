<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant admin plane authority boundary (design.md §136: "role per site"). An
 * authenticated user may enter a site's /admin only if they belong to THAT site —
 * i.e. they hold at least one role on the current site (spatie team = current
 * site_id, auto-scoped by the team resolver) — or are a platform operator (Bảo Sơn
 * super_admin reaches any tenant for support). Authentication alone is not site
 * authority; this closes the "authenticated session on a foreign tenant" gap.
 *
 * Runs after auth + SetCurrentSite, so the user and the site team are both resolved.
 */
final class EnsureTenantMember
{
    public function __construct(private readonly PlatformAccess $platform) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        // Platform operators carry no per-site role (they live at team 0) but must
        // reach every tenant's admin.
        if ($this->platform->isSuperAdmin($user)) {
            return $next($request);
        }

        // Membership = at least one role on the current site (team = site_id).
        abort_unless($user->roles()->exists(), 403, 'You are not a member of this site.');

        return $next($request);
    }
}
