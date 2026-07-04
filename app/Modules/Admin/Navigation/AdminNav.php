<?php

declare(strict_types=1);

namespace App\Modules\Admin\Navigation;

use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * The tenant admin menu (design.md §15.2) as a NESTED tree. Content sections become
 * expandable groups of {List · Add new}; feature modules are leaves; Settings is a group of
 * tabs. Three-ring gating (§2.7): a feature module shows only if the SITE is entitled to it
 * AND the user is PERMITTED (RBAC) — while the routes carry `can:` middleware as the real
 * access boundary (nav hiding is UX, not security). Iterating content_sections directly (not
 * the flat catalog) surfaces every provisioned type — pages, posts AND the collections that
 * used to be invisible (services/products/projects). Fail-closed: no user → empty menu.
 */
final class AdminNav
{
    /**
     * @return list<NavItem>
     */
    public function items(?Authenticatable $user): array
    {
        if (! $user instanceof Authenticatable) {
            return [];
        }

        $gate = Gate::forUser($user);
        $can = static fn (?string $permission): bool => $permission === null || $gate->allows($permission);
        $entitled = app(Entitlement::class)->entitledModuleKeys();
        $request = request();

        $items = [];

        // Dashboard — always.
        $items[] = new NavItem(
            key: 'dashboard',
            label: __('admin.dashboard'),
            url: route('admin.dashboard'),
            icon: 'dashboard',
            active: $request->routeIs('admin.dashboard'),
        );

        // Content sections → an expandable group each, of {List, Add new}. A section EXISTS only
        // if provisioned for this site, so its presence is the entitlement; RBAC still gates
        // (view to see, create to add). The parent is a pure toggle (no url).
        if ($can('entries.view')) {
            $onEntries = $request->routeIs('admin.entries.*');
            $canCreate = $can('entries.create');

            foreach (ContentSection::query()->orderBy('sort_order')->orderBy('id')->get() as $section) {
                $inSection = $onEntries && $request->query('section') === $section->key;

                $children = [
                    new NavItem(
                        key: "{$section->key}.list",
                        label: __('admin.list'),
                        url: route('admin.entries.index', ['section' => $section->key]),
                        active: $inSection && $request->routeIs('admin.entries.index'),
                        permission: 'entries.view',
                    ),
                ];

                if ($canCreate) {
                    $children[] = new NavItem(
                        key: "{$section->key}.create",
                        label: __('admin.add_new'),
                        url: route('admin.entries.create', ['section' => $section->key]),
                        active: $inSection && $request->routeIs('admin.entries.create'),
                        permission: 'entries.create',
                    );
                }

                $items[] = new NavItem(
                    key: $section->key,
                    label: $section->label,
                    icon: $section->icon ?? 'file-text',
                    active: $inSection,
                    children: $children,
                    permission: 'entries.view',
                );
            }
        }

        // Feature leaves. entitlementKey = the ModuleCatalog key to check site entitlement
        // against (null = a core capability, not a per-site module → RBAC only). Categories +
        // Menu builder point at placeholder pages until their real UIs ship.
        $features = [
            // [key, labelKey, route, icon, permission, entitlementKey]
            ['taxonomies', 'admin.categories', 'admin.taxonomies.index', 'file-text', 'taxonomies.manage', null],
            ['media', 'admin.media', 'admin.media.index', 'image', 'media.view', 'media'],
            ['contacts', 'admin.contacts', 'admin.forms.index', 'mail', 'forms.view', 'contacts'],
            ['menus', 'admin.menu_builder', 'admin.menus.index', 'menu', 'menus.manage', 'menus'],
            ['users', 'admin.users', 'admin.users.index', 'users', 'users.manage', 'users'],
        ];

        foreach ($features as [$key, $labelKey, $routeName, $icon, $permission, $entitlementKey]) {
            if ($entitlementKey !== null && ! in_array($entitlementKey, $entitled, true)) {
                continue; // site not entitled to this module
            }
            if (! $can($permission)) {
                continue; // user not permitted
            }

            $items[] = new NavItem(
                key: $key,
                label: __($labelKey),
                url: route($routeName),
                icon: $icon,
                active: $request->routeIs(str_replace('.index', '', $routeName).'*'),
                permission: $permission,
            );
        }

        // Settings → group of tabs. Shown if the site carries the settings module (corporate
        // always does). Security is every-member; the site-wide tabs need settings.manage, so a
        // plain member sees Settings with only Security under it.
        if (in_array('settings', $entitled, true)) {
            $settingsChildren = [
                new NavItem(
                    key: 'settings.security',
                    label: __('admin.security'),
                    url: route('admin.settings.security'),
                    active: $request->routeIs('admin.settings.security'),
                ),
            ];

            if ($can('settings.manage')) {
                foreach ([
                    ['settings.email', 'admin.email_settings', 'admin.settings.email'],
                    ['settings.social', 'admin.social_login', 'admin.settings.social'],
                    ['settings.appearance', 'admin.appearance', 'admin.settings.appearance'],
                ] as [$sKey, $sLabelKey, $sRoute]) {
                    $settingsChildren[] = new NavItem(
                        key: $sKey,
                        label: __($sLabelKey),
                        url: route($sRoute),
                        active: $request->routeIs($sRoute),
                        permission: 'settings.manage',
                    );
                }
            }

            $items[] = new NavItem(
                key: 'settings',
                label: __('admin.settings'),
                icon: 'settings',
                active: $request->routeIs('admin.settings*'),
                children: $settingsChildren,
            );
        }

        return $items;
    }
}
