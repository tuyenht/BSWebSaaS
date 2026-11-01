<?php

use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Registry\FeatureRegistry;
use App\Modules\Tenancy\Registry\ModuleCatalog;

/*
 * The registries a custom role is validated against. A role's permission ceiling (INV-R1) is
 * "the permissions of the modules this site is entitled to", so every tenant permission must be
 * OWNED by at least one module — an orphan permission would silently fall outside every ceiling.
 * Implications (INV-R5) must name real permissions and hold for the shipped templates too, or the
 * normalizer would make a custom role differ from the template it was forked from.
 */

it('gives every tenant permission an owning module (no orphan outside every ceiling)', function (): void {
    $prefixes = array_filter(array_column(ModuleCatalog::all(), 'permission_prefix'));

    $orphans = array_values(array_filter(
        PermissionRegistry::TENANT_PERMISSIONS,
        fn (string $permission): bool => ! in_array(PermissionRegistry::prefixOf($permission), $prefixes, true),
    ));

    expect($orphans)->toBe([]);
});

it('only implies real tenant permissions', function (): void {
    foreach (PermissionRegistry::IMPLIES as $permission => $implied) {
        expect(PermissionRegistry::TENANT_PERMISSIONS)->toContain($permission);
        foreach ($implied as $required) {
            expect(PermissionRegistry::TENANT_PERMISSIONS)->toContain($required);
        }
    }
});

it('closes a permission set under its implications', function (): void {
    expect(PermissionRegistry::withImplied(['catalog.manage', 'entries.edit.any']))
        ->toEqualCanonicalizing(['catalog.manage', 'catalog.view', 'entries.edit.any', 'entries.view']);
});

it('lets whoever manages domains also see them', function (): void {
    expect(PermissionRegistry::TENANT_PERMISSIONS)->toContain('domains.view')
        ->and(PermissionRegistry::withImplied(['domains.manage']))->toEqualCanonicalizing(['domains.manage', 'domains.view']);
});

it('grants the read-only domains permission to editor and viewer, not to author or contributor', function (): void {
    $templates = PermissionRegistry::tenantRoleTemplates('corporate');
    $holds = function (string $name) use ($templates): bool {
        $permissions = $templates[$name]['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $templates[$name]['permissions'];

        return in_array('domains.view', $permissions, true);
    };

    expect($holds('owner'))->toBeTrue()
        ->and($holds('admin'))->toBeTrue()
        ->and($holds('editor'))->toBeTrue()
        ->and($holds('viewer'))->toBeTrue()
        ->and($holds('author'))->toBeFalse()
        ->and($holds('contributor'))->toBeFalse()
        ->and(in_array('domains.manage', $templates['editor']['permissions'], true))->toBeFalse()
        ->and(in_array('domains.manage', $templates['viewer']['permissions'], true))->toBeFalse();
});

it('ships every role template already closed under implication', function (): void {
    foreach (PermissionRegistry::tenantRoleTemplates('corporate') as $name => $template) {
        $permissions = $template['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $template['permissions'];

        expect(PermissionRegistry::withImplied($permissions))->toEqualCanonicalizing($permissions, "template [{$name}] is not closed");
    }
});

it('reserves every template and platform role name', function (): void {
    expect(PermissionRegistry::reservedRoleNames())
        ->toContain('owner', 'admin', 'editor', 'author', 'contributor', 'viewer', 'super_admin', 'support', 'billing');
});

it('registers custom roles as a boolean feature that is off by default', function (): void {
    expect(FeatureRegistry::has('rbac.custom_roles'))->toBeTrue()
        ->and(FeatureRegistry::all()['rbac.custom_roles']['kind'])->toBe('boolean')
        ->and(FeatureRegistry::default('rbac.custom_roles'))->toBeFalse();
});
