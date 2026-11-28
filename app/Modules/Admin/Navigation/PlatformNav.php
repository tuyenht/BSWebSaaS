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
    /** Screens that draw their own trail in their header: every console settings screen. */
    private const NO_SHELL_TRAIL = ['platform.dashboard', 'platform.settings.*'];

    /**
     * Top-level screens whose trail steps aside below 600px and on a short landscape screen: the
     * screens the shell board (shell-screens.js) marks `lvl1` or heads with a short main button
     * («Thêm»), whose way back on a phone is the menu.
     */
    private const TOP_LEVEL_SCREENS = ['platform.tenants.index', 'platform.sites.index', 'platform.support.index', 'platform.plans.index'];

    public function __construct(private PlatformAccess $platform) {}

    /**
     * The page's trail above its title, as on a website's admin (AdminNav::trail): ⌂ › the menu
     * entry, on that entry's own page. A screen below an entry (a customer, a website, a plan, a
     * website's domains) leads with its own «‹ back» row instead, so it gets none; a page outside the
     * menu is named by its title; a settings screen draws its own.
     *
     * @param  iterable<NavSection>  $sections
     * @return list<array{label: string, url: string|null}>
     */
    public function trail(iterable $sections, ?string $title = null): array
    {
        $request = request();
        if ($request->routeIs(...self::NO_SHELL_TRAIL)) {
            return [];
        }

        foreach ($sections as $section) {
            foreach ($section->items as $item) {
                if ($item->active) {
                    return $item->url === $request->url() ? [['label' => $item->label, 'url' => null]] : [];
                }
            }
        }

        return $title === null || $title === '' ? [] : [['label' => $title, 'url' => null]];
    }

    /** Whether this request is one of the top-level screens, whose trail is hidden on phones. */
    public function onTopLevelScreen(): bool
    {
        return request()->routeIs(...self::TOP_LEVEL_SCREENS);
    }

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
                'plans' => ['admin.platform_nav_plans', [
                    ['plans', $may('platform.billing.manage') || $may('platform.site_types.manage'), 'platform.plans.index', 'platform.plans.*', 'admin.platform_plans', 'ri-price-tag-3'],
                ]],
                'operations' => ['admin.platform_nav_operations', [
                    ['audit', $may('platform.audit.view'), 'platform.audit.index', 'platform.audit.*', 'admin.platform_audit', 'ri-file-shield-2'],
                    ['support', $may('platform.sites.impersonate'), 'platform.support.index', 'platform.support.*', 'admin.platform_support', 'ri-login-circle'],
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
