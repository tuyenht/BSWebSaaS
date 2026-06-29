<?php

namespace App\Modules\Admin\Navigation;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

/**
 * Operator console nav (platform plane). Mirrors AdminNav but gates on PLATFORM permissions
 * (sentinel team 0) via PlatformAccess. A link appears only when its route is registered
 * (Route::has — so Sites/Audit light up automatically when S2/S4 ship) AND the operator may
 * see it. Computed inside ONE onPlatform() snapshot so the per-link permission checks share a
 * single team switch (cheap). super_admin is allowed regardless of perm materialization.
 */
final class PlatformNav
{
    public function __construct(private readonly PlatformAccess $platform) {}

    /**
     * @return list<NavItem>
     */
    public function items(?Authenticatable $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $isSuper = $this->platform->isSuperAdmin($user);

        return $this->platform->onPlatform(function () use ($user, $isSuper): array {
            $items = [];

            $maybe = function (bool $visible, string $routeName, string $labelKey, string $key) use (&$items): void {
                if ($visible && Route::has($routeName)) {
                    $items[] = new NavItem(key: $key, label: __($labelKey), url: route($routeName));
                }
            };

            $maybe($isSuper || $user->hasPermissionTo('platform.tenants.view'), 'platform.tenants.index', 'admin.platform_tenants', 'tenants');
            $maybe($isSuper || $user->hasPermissionTo('platform.sites.view'), 'platform.sites.index', 'admin.platform_sites', 'sites');
            $maybe($isSuper || $user->hasPermissionTo('platform.audit.view'), 'platform.audit.index', 'admin.platform_audit', 'audit');
            $maybe($isSuper, 'platform.settings.email', 'admin.settings', 'settings');

            return $items;
        });
    }
}
