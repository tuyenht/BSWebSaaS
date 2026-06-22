<?php

use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Registry\ModuleCatalog;

/*
 * T2 increment-2 — ModuleCatalog is the source of truth for the admin-nav modules (design.md
 * §15.2). 12 modules, snake_case keys (D7), tri-locale labels, sort order, and a permission_prefix
 * that must resolve to a real permission (catalog ⊆ PermissionRegistry — no orphan gates). The
 * render consumer (AdminNav) lands in increment-3; here the catalog is pinned as a tested contract.
 */

it('defines exactly the 12 canonical admin-nav modules with unique snake_case keys', function () {
    $keys = array_column(ModuleCatalog::all(), 'key');
    sort($keys);

    expect($keys)->toBe([
        'banners', 'contacts', 'dashboard', 'media', 'menus', 'pages',
        'posts', 'profile', 'roles', 'settings', 'trash', 'users',
    ])->and(array_unique($keys))->toHaveCount(12);

    foreach ($keys as $key) {
        expect($key)->toMatch('/^[a-z][a-z0-9_]*$/'); // D7 snake_case
    }
});

it('gives every module tri-locale labels, a sort order, and a real permission_prefix', function () {
    $permissions = PermissionRegistry::permissions();

    foreach (ModuleCatalog::all() as $module) {
        expect($module['label'])->toHaveKeys(['vi', 'en', 'ja'])
            ->and($module)->toHaveKey('sort_order');

        $prefix = $module['permission_prefix'] ?? null;
        if (is_string($prefix) && $prefix !== '') {
            $covered = array_filter($permissions, fn (string $p): bool => str_starts_with($p, $prefix.'.'));
            expect($covered)->not->toBeEmpty("permission_prefix [{$prefix}] has no matching permission");
        }
    }
});

it('flags media as needing the tier-3 media stack', function () {
    $media = collect(ModuleCatalog::all())->firstWhere('key', 'media');
    expect($media['needs_tier3'])->toBeTrue();
});

it('maps the corporate site_type to a module set drawn from the catalog', function () {
    $catalogKeys = array_column(ModuleCatalog::all(), 'key');
    $corporate = ModuleCatalog::modulesFor('corporate');

    expect($corporate)->not->toBeEmpty()
        ->and(array_diff($corporate, $catalogKeys))->toBe([]); // ⊆ catalog
});

/*
 * Increment-3 additive nav contract: the catalog also carries the render structure —
 * a `route` (a feature module's admin route, or null for content slots / unbuilt
 * modules) and a `nav_permission` (the nav-visibility gate, or null = always visible).
 * permission_prefix is retained (asserted above) — it is a separate axis.
 */

it('gives every module a route slot and a nav_permission that resolves to a real permission', function () {
    $permissions = PermissionRegistry::permissions();

    foreach (ModuleCatalog::all() as $module) {
        expect($module)->toHaveKeys(['route', 'nav_permission']);

        if (is_string($module['route'])) {
            expect($module['route'])->toStartWith('admin.');
        }

        // No orphan nav gate: a non-null nav_permission must be a real permission.
        if (is_string($module['nav_permission'])) {
            expect($permissions)->toContain($module['nav_permission']);
        }
    }
});

it('routes only the built feature modules; content slots (posts/pages) and unbuilt modules are route=null', function () {
    $routes = collect(ModuleCatalog::all())->pluck('route', 'key')->all();

    expect($routes['dashboard'])->toBe('admin.dashboard')
        ->and($routes['users'])->toBe('admin.users.index')
        ->and($routes['settings'])->toBe('admin.settings')
        ->and($routes['posts'])->toBeNull()   // content slot — filled by content_sections
        ->and($routes['pages'])->toBeNull()   // content slot
        ->and($routes['media'])->toBeNull();  // unbuilt feature module
});

it('gates users on users.manage and leaves dashboard/settings always-visible (null nav_permission)', function () {
    $navPermission = collect(ModuleCatalog::all())->pluck('nav_permission', 'key')->all();

    expect($navPermission['users'])->toBe('users.manage')
        ->and($navPermission['dashboard'])->toBeNull()
        ->and($navPermission['settings'])->toBeNull();
});
