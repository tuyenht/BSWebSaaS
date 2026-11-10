<?php

namespace App\Modules\Admin\Navigation;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Operator console sidebar (platform plane): headed groups of links, gated on PLATFORM
 * permissions (sentinel team 0) via PlatformAccess. A link appears only when its route is
 * registered (Route::has — a screen without a back end has no route, so it stays hidden until it
 * ships) AND the operator may see it; a heading with no visible link is dropped. Computed inside
 * ONE onPlatform() snapshot so the per-link permission checks share a single team switch.
 * super_admin is allowed regardless of perm materialization. Never touches a tenant context.
 */
final readonly class PlatformNav
{
    public function __construct(private PlatformAccess $platform) {}

    /**
     * @param  string|null  $currentRoute  the request's route name — the link whose group it is in is the active one
     * @return list<NavSection>
     */
    public function sections(?Authenticatable $user, ?string $currentRoute): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $isSuper = $this->platform->isSuperAdmin($user);

        return $this->platform->onPlatform(function () use ($user, $isSuper, $currentRoute): array {
            $may = fn (string $permission): bool => $isSuper || $user->hasPermissionTo($permission);

            // [key, visible, route, active pattern, label key, icon]
            $groups = [
                'customers' => ['admin.platform_nav_customers', [
                    ['tenants', $may('platform.tenants.view'), 'platform.tenants.index', 'platform.tenants.*', 'admin.platform_tenants', 'ri-building-2'],
                    ['sites', $may('platform.sites.view'), 'platform.sites.index', 'platform.sites.*', 'admin.platform_sites', 'ri-window'],
                ]],
                'operations' => ['admin.platform_nav_operations', [
                    ['audit', $may('platform.audit.view'), 'platform.audit.index', 'platform.audit.*', 'admin.platform_audit', 'ri-file-shield-2'],
                    ['settings', $isSuper, 'platform.settings.email', 'platform.settings.*', 'admin.platform_settings', 'ri-settings-3'],
                ]],
            ];

            $sections = [];
            foreach ($groups as $key => [$labelKey, $links]) {
                $items = [];
                foreach ($links as [$itemKey, $visible, $routeName, $activePattern, $itemLabelKey, $icon]) {
                    if ($visible && Route::has($routeName)) {
                        $items[] = new NavItem(
                            key: $itemKey,
                            label: __($itemLabelKey),
                            url: route($routeName),
                            icon: $icon,
                            active: $currentRoute !== null && Str::is($activePattern, $currentRoute),
                        );
                    }
                }

                if ($items !== []) {
                    $sections[] = new NavSection(key: $key, label: __($labelKey), items: $items);
                }
            }

            return $sections;
        });
    }
}
