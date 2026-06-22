<?php

namespace App\Modules\Admin\Navigation;

use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Registry\ModuleCatalog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * The admin menu (design.md §15.2). ModuleCatalog is the single STRUCTURAL source —
 * order, identity, route and the nav-visibility gate of every module — intersected with
 * what the current site is ENTITLED to (Entitlement: site_type set ∪ site_modules
 * override) and what the user is PERMITTED to see (RBAC). Two label axes: a feature
 * module takes its label from the i18n layer (__(), display-locale); a content slot
 * (posts/pages) is filled from its content_section (content-locale). One producer,
 * fail-closed (no user / no site → empty menu).
 */
final class AdminNav
{
    /**
     * @return list<NavItem>
     */
    public function items(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $entitled = app(Entitlement::class)->entitledModuleKeys();

        // content_sections is BelongsToSite — auto-scoped + fail-closed to the current site.
        $sections = ContentSection::query()->get()->keyBy('key');

        $request = request();

        $items = [];

        foreach (ModuleCatalog::all() as $module) {
            $key = $module['key'];

            if (! in_array($key, $entitled, true)) {
                continue; // not entitled for this site (site_type set ∪ override)
            }

            $route = $module['route'];

            // Feature module: links to its own admin route, gated by nav_permission
            // (null = always visible), labelled via the i18n layer (display-locale).
            if ($route !== null) {
                $permission = $module['nav_permission'];

                if ($permission !== null && ! Gate::forUser($user)->allows($permission)) {
                    continue;
                }

                $label = __("admin.{$key}");

                $items[] = new NavItem(
                    key: $key,
                    label: is_string($label) ? $label : $key,
                    url: route($route),
                    icon: $module['icon'],
                    active: $request->routeIs($this->activePattern($route)),
                );

                continue;
            }

            // Content slot: filled by the matching provisioned content_section, positioned
            // by the catalog sort_order, gated by the section's {prefix}.view (no prefix =
            // visible, mirroring the prior behaviour).
            $section = $sections->get($key);

            if (! $section instanceof ContentSection) {
                continue; // route=null and no provisioned section → not (yet) renderable
            }

            $prefix = $section->permission_prefix;

            if (is_string($prefix) && $prefix !== '' && ! Gate::forUser($user)->allows("{$prefix}.view")) {
                continue;
            }

            $items[] = new NavItem(
                key: $section->key,
                label: $section->label,
                url: route('admin.entries.index', ['section' => $section->key]),
                icon: $module['icon'],
                active: $request->routeIs('admin.entries.index')
                    && $request->query('section') === $section->key,
            );
        }

        return $items;
    }

    /**
     * Route-name pattern that marks a feature item active across its sub-routes:
     * 'admin.users.index' → 'admin.users*', 'admin.settings' → 'admin.settings*',
     * 'admin.dashboard' → 'admin.dashboard*'.
     */
    private function activePattern(string $route): string
    {
        return rtrim((string) preg_replace('/\.index$/', '', $route), '.').'*';
    }
}
