<?php

declare(strict_types=1);

namespace App\Modules\Admin\Navigation;

use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * The tenant admin menu (design.md §15.2) as a NESTED tree. Content sections become
 * expandable groups of {Add new · List}; feature modules are leaves; Settings is a group of
 * tabs. Three-ring gating (§2.7): a feature module shows only if the SITE is entitled to it
 * AND the user is PERMITTED (RBAC) — while the routes carry `can:` middleware as the real
 * access boundary (nav hiding is UX, not security). Iterating content_sections directly (not
 * the flat catalog) surfaces every provisioned type — pages, posts AND the collections that
 * used to be invisible (services/products/projects). Fail-closed: no user → empty menu.
 */
final class AdminNav
{
    /**
     * A sensible default glyph per known content-section key, so each type reads distinctly in
     * the sidebar instead of sharing one generic document icon. A section's own DB icon still
     * wins when set; an unknown key falls back to the document glyph.
     *
     * @var array<string, string>
     */
    private const SECTION_ICONS = [
        'pages' => 'file-text',
        'posts' => 'newspaper',
        'services' => 'briefcase',
        'products' => 'cube',
        'projects' => 'folder',
    ];

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

        // Content sections → an expandable group each, of {Add new, List, (Categories)}. A section
        // EXISTS only if provisioned for this site (its presence is the entitlement); RBAC gates
        // per-section by its OWN permission_prefix (defaults to 'entries'). A section that owns a
        // taxonomy also gets a Categories child scoped to that section (?section=…), so each type's
        // categories never mix — and Pages, which has no taxonomy, gets none. Parent is a toggle.
        $onEntries = $request->routeIs('admin.entries.*');
        $onTaxonomies = $request->routeIs('admin.taxonomies.*');

        foreach (ContentSection::query()->orderBy('sort_order')->orderBy('id')->get() as $section) {
            $prefix = $section->permission_prefix ?: 'entries';

            if (! $can("{$prefix}.view")) {
                continue; // user cannot view this section
            }

            $inSection = $onEntries && $request->query('section') === $section->key;
            $inTaxonomy = $onTaxonomies && $request->query('section') === $section->key;

            // Order: Add new · List · Categories (action-forward — owner preference). Add new shows
            // only when the user can create, so a view-only member simply sees List first.
            $children = [];

            if ($can("{$prefix}.create")) {
                $children[] = new NavItem(
                    key: "{$section->key}.create",
                    label: __('admin.add_new'),
                    url: route('admin.entries.create', ['section' => $section->key]),
                    active: $inSection && $request->routeIs('admin.entries.create'),
                    permission: "{$prefix}.create",
                );
            }

            $children[] = new NavItem(
                key: "{$section->key}.list",
                label: __('admin.list'),
                url: route('admin.entries.index', ['section' => $section->key]),
                active: $inSection && $request->routeIs('admin.entries.index'),
                permission: "{$prefix}.view",
            );

            // Categories belong UNDER their content type (never a shared top-level list). Only for
            // sections that own a taxonomy; scoped by section so they never mix. Points at the
            // placeholder until the real taxonomy UI ships.
            if ($section->taxonomy_id !== null && $can('taxonomies.manage')) {
                $children[] = new NavItem(
                    key: "{$section->key}.taxonomy",
                    label: __('admin.categories'),
                    url: route('admin.taxonomies.index', ['section' => $section->key]),
                    active: $inTaxonomy,
                    permission: 'taxonomies.manage',
                );
            }

            $items[] = new NavItem(
                key: $section->key,
                label: $section->localizedLabel(),
                icon: $section->icon ?: (self::SECTION_ICONS[$section->key] ?? 'file-text'),
                active: $inSection || $inTaxonomy,
                children: $children,
                permission: "{$prefix}.view",
            );
        }

        // Feature leaves. entitlementKey = the ModuleCatalog key to check site entitlement
        // against (null = a core capability, not a per-site module → RBAC only). Categories +
        // Menu builder point at placeholder pages until their real UIs ship.
        $features = [
            // [key, labelKey, route, icon, permission, entitlementKey]. Categories are NOT here —
            // they live per content type (see the section loop above), scoped so they never mix.
            ['media', 'admin.media', 'admin.media.index', 'image', 'media.view', 'media'],
            ['contacts', 'admin.contacts', 'admin.forms.index', 'mail', 'forms.view', 'contacts'],
            ['menus', 'admin.menu_builder', 'admin.menus.index', 'menu', 'menus.manage', 'menus'],
            ['users', 'admin.users', 'admin.users.index', 'users', 'users.manage', 'users'],
        ];

        foreach ($features as [$key, $labelKey, $routeName, $icon, $permission, $entitlementKey]) {
            if (! in_array($entitlementKey, $entitled, true)) {
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
