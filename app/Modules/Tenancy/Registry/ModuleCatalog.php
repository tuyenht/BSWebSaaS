<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Registry;

/**
 * Source of truth for the admin-nav modules (design.md §15.2, catalog B1). Each module is a
 * key (snake_case, D7) + tri-locale label + an optional permission_prefix that MUST resolve to a
 * real permission in PermissionRegistry (no orphan gates) + sort order + icon + a needs_tier3 flag.
 *
 * `route` = the admin route a feature module links to — null for content slots (AdminNav fills
 * those from content_sections) and for not-yet-built modules (they stay hidden until a real route
 * exists). `nav_permission` = the nav-visibility gate (null = always visible); kept separate from
 * `permission_prefix` (the content {prefix}.view convention) because e.g. Users gates the nav on
 * `users.manage`. AdminNav (design.md §15.2) intersects this with site entitlement + RBAC.
 */
final class ModuleCatalog
{
    /**
     * @return list<array{key: string, label: array{vi: string, en: string, ja: string}, permission_prefix: string|null, route: string|null, nav_permission: string|null, sort_order: int, icon: string, needs_tier3: bool}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'dashboard', 'label' => ['vi' => 'Bảng điều khiển', 'en' => 'Dashboard', 'ja' => 'ダッシュボード'], 'permission_prefix' => null, 'route' => 'admin.dashboard', 'nav_permission' => null, 'sort_order' => 0, 'icon' => 'dashboard', 'needs_tier3' => false],
            ['key' => 'pages', 'label' => ['vi' => 'Trang', 'en' => 'Pages', 'ja' => 'ページ'], 'permission_prefix' => 'entries', 'route' => null, 'nav_permission' => null, 'sort_order' => 1, 'icon' => 'file', 'needs_tier3' => false],
            ['key' => 'posts', 'label' => ['vi' => 'Bài viết', 'en' => 'Posts', 'ja' => '投稿'], 'permission_prefix' => 'entries', 'route' => null, 'nav_permission' => null, 'sort_order' => 2, 'icon' => 'file-text', 'needs_tier3' => false],
            ['key' => 'media', 'label' => ['vi' => 'Thư viện', 'en' => 'Media', 'ja' => 'メディア'], 'permission_prefix' => 'media', 'route' => 'admin.media.index', 'nav_permission' => 'media.view', 'sort_order' => 3, 'icon' => 'image', 'needs_tier3' => true],
            ['key' => 'contacts', 'label' => ['vi' => 'Liên hệ', 'en' => 'Contacts', 'ja' => 'お問い合わせ'], 'permission_prefix' => 'forms', 'route' => 'admin.forms.index', 'nav_permission' => 'forms.view', 'sort_order' => 4, 'icon' => 'mail', 'needs_tier3' => false],
            ['key' => 'menus', 'label' => ['vi' => 'Menu', 'en' => 'Menus', 'ja' => 'メニュー'], 'permission_prefix' => 'menus', 'route' => null, 'nav_permission' => null, 'sort_order' => 5, 'icon' => 'menu', 'needs_tier3' => false],
            ['key' => 'banners', 'label' => ['vi' => 'Banner', 'en' => 'Banners', 'ja' => 'バナー'], 'permission_prefix' => null, 'route' => null, 'nav_permission' => null, 'sort_order' => 6, 'icon' => 'megaphone', 'needs_tier3' => false],
            ['key' => 'users', 'label' => ['vi' => 'Người dùng', 'en' => 'Users', 'ja' => 'ユーザー'], 'permission_prefix' => 'users', 'route' => 'admin.users.index', 'nav_permission' => 'users.manage', 'sort_order' => 7, 'icon' => 'users', 'needs_tier3' => false],
            ['key' => 'roles', 'label' => ['vi' => 'Vai trò', 'en' => 'Roles', 'ja' => 'ロール'], 'permission_prefix' => 'roles', 'route' => null, 'nav_permission' => null, 'sort_order' => 8, 'icon' => 'shield', 'needs_tier3' => false],
            ['key' => 'settings', 'label' => ['vi' => 'Cài đặt', 'en' => 'Settings', 'ja' => '設定'], 'permission_prefix' => 'settings', 'route' => 'admin.settings', 'nav_permission' => null, 'sort_order' => 9, 'icon' => 'settings', 'needs_tier3' => false],
            ['key' => 'trash', 'label' => ['vi' => 'Thùng rác', 'en' => 'Trash', 'ja' => 'ゴミ箱'], 'permission_prefix' => 'entries', 'route' => null, 'nav_permission' => null, 'sort_order' => 10, 'icon' => 'trash', 'needs_tier3' => false],
            ['key' => 'profile', 'label' => ['vi' => 'Hồ sơ', 'en' => 'Profile', 'ja' => 'プロフィール'], 'permission_prefix' => null, 'route' => null, 'nav_permission' => null, 'sort_order' => 11, 'icon' => 'user', 'needs_tier3' => false],
        ];
    }

    /**
     * The module key set a site_type grants (design.md §15.2 — site_modules overrides add on top).
     * GĐ1: `corporate` carries the full catalog. Unknown type → empty.
     *
     * @return list<string>
     */
    public static function modulesFor(string $siteType): array
    {
        return match ($siteType) {
            'corporate' => array_column(self::all(), 'key'),
            default => [],
        };
    }
}
