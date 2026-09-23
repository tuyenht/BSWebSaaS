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
     * What holding a permission requires alongside it (INV-R5): acting on a resource means seeing
     * it. A custom role is normalized through {@see withImplied()} on save, so no role ever carries
     * `catalog.manage` without `catalog.view`. The shipped templates are already closed under this
     * map (PermissionCatalogTest) — a fork therefore normalizes to exactly its source.
     *
     * @var array<string, list<string>>
     */
    public const array IMPLIES = [
        'entries.create' => ['entries.view'],
        'entries.edit.own' => ['entries.view'],
        'entries.edit.any' => ['entries.view'],
        'entries.delete.own' => ['entries.view'],
        'entries.delete.any' => ['entries.view'],
        'entries.submit' => ['entries.view'],
        'entries.review' => ['entries.view'],
        'entries.approve' => ['entries.view'],
        'entries.publish' => ['entries.view'],
        'media.upload' => ['media.view'],
        'media.delete' => ['media.view'],
        'forms.manage' => ['forms.view'],
        'catalog.manage' => ['catalog.view'],
    ];

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return [...self::TENANT_PERMISSIONS, ...self::PLATFORM_PERMISSIONS];
    }

    /** The resource a permission acts on (`entries.edit.own` → `entries`) — the module-ownership key. */
    public static function prefixOf(string $permission): string
    {
        return explode('.', $permission, 2)[0];
    }

    /**
     * $permissions plus everything they imply, de-duplicated (one level is enough: implied
     * permissions imply nothing further).
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function withImplied(array $permissions): array
    {
        $closed = $permissions;
        foreach ($permissions as $permission) {
            array_push($closed, ...(self::IMPLIES[$permission] ?? []));
        }

        return array_values(array_unique($closed));
    }

    /**
     * Names a custom role may never take: every system role template and every platform role —
     * code resolves those by name (`assignRole('owner')`, the platform gate), so a look-alike
     * custom role would be read as the real one. Compared case-insensitively by the callers.
     *
     * @return list<string>
     */
    public static function reservedRoleNames(): array
    {
        return [...self::platformRoleNames(), ...array_keys(self::corporateRoleTemplates())];
    }

    /** The highest level a tenant template grants — nothing custom may sit at or above it. */
    public static function topTenantLevel(): int
    {
        return max(0, ...array_column(self::corporateRoleTemplates(), 'level'));
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
