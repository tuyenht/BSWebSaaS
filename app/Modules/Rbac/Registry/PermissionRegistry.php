<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Registry;

/**
 * Single source of truth for permissions + system role templates (design.md §15).
 * `rbac:sync` materializes permissions + platform roles from here; ProvisionSite
 * instantiates the tenant role templates per-site. Add capability = edit here.
 */
final class PermissionRegistry
{
    /** Platform scope sentinel — never a real site_id (identity starts at 1). */
    public const int PLATFORM_TEAM_ID = 0;

    public const string GUARD = 'web';

    /** @var list<string> tenant-scoped permissions (resource.action) */
    public const array TENANT_PERMISSIONS = [
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
        'catalog.view',
        'catalog.manage',
        'settings.manage',
        'users.invite',
        'users.manage',
        'roles.manage',
        'domains.manage',
    ];

    /** @var list<string> platform-scoped (Bảo Sơn operator) permissions */
    public const array PLATFORM_PERMISSIONS = [
        'platform.tenants.view',
        'platform.tenants.manage',
        'platform.sites.view',
        'platform.sites.manage',
        'platform.entitlements.manage',
        'platform.workflows.manage',
        'platform.site_types.manage',
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
     * The platform role names — the operator set that gates the platform plane. Derived from
     * platformRoles() so the login gate and EnsurePlatformAccess share one definition and can
     * never drift when a role is added or removed.
     *
     * @return list<string>
     */
    public static function platformRoleNames(): array
    {
        return array_keys(self::platformRoles());
    }

    /**
     * Tenant role templates for a site_type, instantiated per-site by ProvisionSite (team = site_id).
     * Keyed by site_type (design.md §15.2); GĐ1 ships only `corporate`. An unknown type yields an
     * empty set so a caller never silently inherits the wrong shape. '*' = every TENANT permission
     * (never platform permissions).
     *
     * @return array<string, array{level: int, permissions: list<string>|'*'}>
     */
    public static function tenantRoleTemplates(string $siteType): array
    {
        return match ($siteType) {
            'corporate' => self::corporateRoleTemplates(),
            default => [],
        };
    }

    /**
     * The `corporate` functional shape — the six baseline tenant roles (An Vinh = B2B cơ khí).
     *
     * @return array<string, array{level: int, permissions: list<string>|'*'}>
     */
    private static function corporateRoleTemplates(): array
    {
        return [
            'owner' => ['level' => 90, 'permissions' => '*'],
            'admin' => ['level' => 70, 'permissions' => self::TENANT_PERMISSIONS],
            'editor' => ['level' => 50, 'permissions' => [
                'entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any',
                'entries.submit', 'entries.review', 'entries.approve', 'entries.publish',
                'media.view', 'media.upload', 'media.delete',
                'taxonomies.manage', 'menus.manage', 'forms.view', 'forms.manage',
                'catalog.view', 'catalog.manage',
            ]],
            'author' => ['level' => 30, 'permissions' => [
                'entries.view', 'entries.create', 'entries.edit.own', 'entries.delete.own',
                'entries.submit', 'media.view', 'media.upload',
            ]],
            'contributor' => ['level' => 20, 'permissions' => [
                'entries.view', 'entries.create', 'entries.edit.own', 'entries.submit', 'media.view',
            ]],
            'viewer' => ['level' => 10, 'permissions' => [
                'entries.view', 'media.view', 'forms.view', 'catalog.view',
            ]],
        ];
    }
}
