<?php

use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Registry\ModuleCatalog;

/*
 * T2 increment-2 — ModuleCatalog is the source of truth for the admin-nav modules (design.md
 * §15.2). 13 modules, snake_case keys (D7), tri-locale labels, sort order, and a permission_prefix
 * that must resolve to a real permission (catalog ⊆ PermissionRegistry — no orphan gates). The
 * render consumer (AdminNav) lands in increment-3; here the catalog is pinned as a tested contract.
 */

it('defines exactly the 13 canonical admin-nav modules with unique snake_case keys', function (): void {
    $keys = array_column(ModuleCatalog::all(), 'key');
    sort($keys);

    expect($keys)->toBe([
        'banners', 'catalog', 'contacts', 'dashboard', 'media', 'menus', 'pages',
        'posts', 'profile', 'roles', 'settings', 'trash', 'users',
    ])->and(array_unique($keys))->toHaveCount(13);

    foreach ($keys as $key) {
        expect($key)->toMatch('/^[a-z][a-z0-9_]*$/'); // D7 snake_case
    }
});

it('gives every module tri-locale labels, a sort order, and a real permission_prefix', function (): void {
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

it('flags media as needing the tier-3 media stack', function (): void {
    $media = collect(ModuleCatalog::all())->firstWhere('key', 'media');
    expect($media['needs_tier3'])->toBeTrue();
});

it('maps the corporate site_type to a module set drawn from the catalog', function (): void {
    $catalogKeys = array_column(ModuleCatalog::all(), 'key');
    $corporate = ModuleCatalog::modulesFor('corporate');

    expect($corporate)->not->toBeEmpty()
        ->and(array_diff($corporate, $catalogKeys))->toBe([]); // ⊆ catalog
});
