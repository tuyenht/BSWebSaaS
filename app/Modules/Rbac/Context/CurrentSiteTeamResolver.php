<?php

namespace App\Modules\Rbac\Context;

use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Binds the spatie team context to the resolved tenant: by default the team id IS
 * the current site (so a permission check auto-scopes to the active site); an
 * explicit override (console, platform checks) wins while set. Platform scope = 0.
 *
 * STATELESS by design: spatie's PermissionRegistrar instantiates this resolver with
 * `new` (not the container), so all mutable state is delegated to the shared
 * TeamContext singleton — read here via the container so every caller agrees.
 */
final class CurrentSiteTeamResolver implements PermissionsTeamResolver
{
    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        app(TeamContext::class)->force(is_numeric($id) ? (int) $id : null);
    }

    public function getPermissionsTeamId(): ?int
    {
        $context = app(TeamContext::class);

        if ($context->isForced()) {
            return $context->teamId();
        }

        $current = app(CurrentSite::class);

        return $current->hasId() ? $current->id() : PermissionRegistry::PLATFORM_TEAM_ID;
    }
}
