<?php

namespace App\Modules\Rbac\Registry;

/**
 * Single source of truth for permissions + system role templates (design.md §15).
 * `rbac:sync` materializes permissions + platform roles from here; ProvisionSite
 * instantiates the tenant role templates per-site. Add capability = edit here.
 */
final class PermissionRegistry
{
    /** Platform scope sentinel — never a real site_id (identity starts at 1). */
    public const PLATFORM_TEAM_ID = 0;

    public const GUARD = 'web';

    /** @var list<string> tenant-scoped permissions (resource.action) */
    public const TENANT_PERMISSIONS = [
        'entries.view',
        'entries.create',
        'entries.edit.own',
        'entries.edit.any',
        'entries.delete.own',
        'entries.delete.any',
        'entries.submit',
        'entries.review',
        'entries.approve',
        'entries.publish',
        'media.view',
        'media.upload',
        'media.delete',
        'taxonomies.manage',
        'menus.manage',
        'forms.view',
        'forms.manage',
        'settings.manage',
        'users.invite',
        'users.manage',
        'roles.manage',
        'domains.manage',
    ];

    /** @var list<string> platform-scoped (Bảo Sơn operator) permissions */
    public const PLATFORM_PERMISSIONS = [
        'platform.tenants.view',
        'platform.tenants.manage',
        'platform.sites.view',
        'platform.sites.manage',
        'platform.entitlements.manage',
        'platform.themes.manage',
        'platform.billing.manage',
        'platform.users.manage',
        'platform.audit.view',
    ];

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return [...self::TENANT_PERMISSIONS, ...self::PLATFORM_PERMISSIONS];
    }

    /**
     * Platform roles (site_id = PLATFORM_TEAM_ID). '*' resolves to every permission.
     *
     * @return array<string, array{level: int, permissions: list<string>|'*'}>
     */
    public static function platformRoles(): array
    {
        return [
            'super_admin' => ['level' => 100, 'permissions' => '*'],
            'support' => ['level' => 50, 'permissions' => [
                'platform.tenants.view', 'platform.sites.view', 'platform.audit.view',
            ]],
            'billing' => ['level' => 40, 'permissions' => [
                'platform.billing.manage', 'platform.tenants.view',
            ]],
        ];
    }

    /**
     * Tenant role templates, instantiated per-site by ProvisionSite (team = site_id).
     * '*' resolves to every TENANT permission (never platform permissions).
     *
     * @return array<string, array{level: int, permissions: list<string>|'*'}>
     */
    public static function tenantRoleTemplates(): array
    {
        return [
            'owner' => ['level' => 90, 'permissions' => '*'],
            'admin' => ['level' => 70, 'permissions' => self::TENANT_PERMISSIONS],
            'editor' => ['level' => 50, 'permissions' => [
                'entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any',
                'entries.submit', 'entries.review', 'entries.approve', 'entries.publish',
                'media.view', 'media.upload', 'media.delete',
                'taxonomies.manage', 'menus.manage', 'forms.view', 'forms.manage',
            ]],
            'author' => ['level' => 30, 'permissions' => [
                'entries.view', 'entries.create', 'entries.edit.own', 'entries.delete.own',
                'entries.submit', 'media.view', 'media.upload',
            ]],
            'contributor' => ['level' => 20, 'permissions' => [
                'entries.view', 'entries.create', 'entries.edit.own', 'entries.submit', 'media.view',
            ]],
            'viewer' => ['level' => 10, 'permissions' => [
                'entries.view', 'media.view', 'forms.view',
            ]],
        ];
    }
}
