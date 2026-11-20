<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Members;

use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\RoleLabel;

/**
 * What a role lets its holder do, in plain words (design 22 "Thành viên"): the dialog «Nhóm này
 * làm được gì?» (every area of the permission catalog, each permission said as done or not done)
 * and the sentence of the roles panel and the invite dialog ("Lan được: …; Không được: …").
 *
 * Both are read from the roles as they are stored — their permissions on this website, their
 * level, whether they are system roles — and from PermissionRegistry's catalog, never from a copy:
 * a role whose permissions change reads differently at once, and a permission added to the catalog
 * shows in the dialog under its area. A permission or area without words in the language files is
 * left out rather than shown by its code (the catalog test keeps every one worded).
 *
 * Drawing only: who may grant what is decided by AssignRole / RevokeRole.
 */
final class RoleAbilities
{
    /** The order the areas are listed in (design 22/«Nhóm quyền»); an area not named here comes after them, in the catalog's order. */
    public const array AREA_ORDER = ['entries', 'media', 'taxonomies', 'menus', 'blocks', 'domains', 'settings', 'forms', 'catalog', 'users', 'roles'];

    /** An "own" permission and the "any" one that holds it: a role with only the second does the first too. */
    public const array OWN_IN_ANY = [
        'entries.edit.own' => 'entries.edit.any',
        'entries.delete.own' => 'entries.delete.any',
        'media.delete.own' => 'media.delete.any',
    ];

    /** The areas that belong to a module a plan may switch off (only the catalog, as design 22 draws it). */
    private const array MODULE_AREAS = ['catalog'];

    /**
     * The areas of the dialog, each with every permission of the catalog in it, done or not.
     *
     * @param  list<string>  $permissions  the role's permission names
     * @return list<array{key: string, name: string, off: string, items: list<array{ok: bool, text: string}>}>
     */
    public static function explain(array $permissions, bool $catalogOn): array
    {
        $has = array_flip(self::tenantOnly($permissions));
        $areas = [];
        foreach (self::areas() as $area => $list) {
            $name = self::words('admin.members.xa.'.$area);
            if ($name === null) {
                continue;
            }
            $items = [];
            foreach ($list as $permission) {
                $item = self::item($permission, $has, $catalogOn);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
            if ($items === []) {
                continue;
            }
            $off = in_array($area, self::MODULE_AREAS, true) && ! $catalogOn ? __('admin.members.x_off', ['area' => $name]) : '';
            $areas[] = ['key' => $area, 'name' => $name, 'off' => $off, 'items' => $items];
        }

        return $areas;
    }

    /**
     * Where a role stands among the website's system roles: «Cao nhất», «Dưới Quản trị», and for a
     * role of the site's own «Ngang …», «Giữa … và …», «Trên …».
     *
     * @param  iterable<Role>  $siteRoles  the website's roles (the system ones form the ladder)
     */
    public static function position(Role $role, iterable $siteRoles): string
    {
        $ladder = [];
        foreach ($siteRoles as $r) {
            if ($r->is_system) {
                $ladder[] = ['name' => RoleLabel::for($r), 'level' => (int) $r->level, 'id' => $r->id];
            }
        }
        usort($ladder, fn (array $a, array $b): int => $b['level'] <=> $a['level']);
        $level = (int) $role->level;
        $firstAt = function (int $at) use ($ladder): string {
            foreach ($ladder as $step) {
                if ($step['level'] === $at) {
                    return $step['name'];
                }
            }

            return '';
        };
        $above = array_values(array_filter($ladder, fn (array $s): bool => $s['level'] > $level));
        $nearUp = $above === [] ? null : $above[count($above) - 1]['level'];
        if ($role->is_system) {
            return $nearUp === null ? __('admin.members.x_top') : __('admin.members.x_below', ['role' => $firstAt($nearUp)]);
        }
        foreach ($ladder as $step) {
            if ($step['level'] === $level) {
                return __('admin.members.x_equal', ['role' => $step['name']]);
            }
        }
        $below = array_values(array_filter($ladder, fn (array $s): bool => $s['level'] < $level));
        $low = $below === [] ? null : $below[0]['name'];
        if ($nearUp === null) {
            return __('admin.members.x_above', ['role' => (string) $low]);
        }
        if ($low === null) {
            return __('admin.members.x_below', ['role' => $firstAt($nearUp)]);
        }

        return __('admin.members.x_between', ['up' => $firstAt($nearUp), 'low' => $low]);
    }

    /**
     * The sentence for someone holding every role of $roles together (their permissions joined),
     * working within $scopes on each axis: what they may do, and what they may not.
     *
     * @param  iterable<Role>  $roles  with their permissions
     * @param  array<string, list<string>|null>  $scopes  by axis (CategoryScopes::POSTS / PRODUCTS): the category
     *                                                    names they are limited to, [] when "only" is chosen with
     *                                                    none picked yet, null (or absent) for every category
     */
    public static function sentence(string $name, iterable $roles, array $scopes, bool $catalogOn): string
    {
        $names = [];
        $level = 0;
        foreach ($roles as $role) {
            if ($role->is_system && (int) $role->level >= CategoryScopes::EXEMPT_LEVEL) {
                return __('admin.members.sm_all', ['name' => $name]);
            }
            $level = max($level, (int) $role->level);
            foreach ($role->permissions as $permission) {
                $names[] = (string) $permission->name;
            }
        }
        $p = array_flip(self::tenantOnly($names));
        $h = fn (string $permission): bool => isset($p[$permission]);
        // a role from the level of the website's administrators is never limited to categories
        $posts = $level >= CategoryScopes::EXEMPT_LEVEL ? null : ($scopes[CategoryScopes::POSTS] ?? null);
        $products = $level >= CategoryScopes::EXEMPT_LEVEL ? null : ($scopes[CategoryScopes::PRODUCTS] ?? null);
        $comma = __('admin.members.sm_comma');
        $join = fn (array $list): string => implode($comma, array_filter($list, fn (mixed $v): bool => is_string($v) && $v !== ''));

        $parts = [];
        $miss = [];
        $entries = array_values(array_filter(array_keys($p), fn (string $k): bool => str_starts_with($k, 'entries.')));
        $works = array_filter($entries, fn (string $k): bool => $k !== 'entries.view') !== [];
        if ($entries === []) {
            $miss[] = __('admin.members.sm_n_posts');
        } else {
            [$cr, $ap, $pb] = [$h('entries.create'), $h('entries.approve'), $h('entries.publish')];
            $t = match (true) {
                ! $works => __('admin.members.sm_e_view'),
                $cr && $h('entries.submit') && ! $pb && ! $ap => __('admin.members.sm_e_submit'),
                default => __('admin.members.sm_e_'.(int) $cr.(int) $ap.(int) $pb),
            };
            if ($h('taxonomies.manage') && $h('entries.view')) {
                $t = __('admin.members.sm_e_tax', ['what' => $t]);
            }
            if ($works) {
                $t = match (true) {
                    $posts === null => __('admin.members.sm_e_every', ['what' => $t]),
                    $posts === [] => __('admin.members.sm_e_none', ['what' => $t]),
                    default => __('admin.members.sm_in', ['what' => $t, 'names' => implode($comma, $posts)]),
                };
            }
            $parts[] = $t;
        }
        if ($catalogOn) {
            if ($h('catalog.manage')) {
                $t = __('admin.members.sm_p_manage');
                if ($h('taxonomies.manage') && $h('catalog.view')) {
                    $t = __('admin.members.sm_p_tax', ['what' => $t]);
                }
                $parts[] = match (true) {
                    $products === null => __('admin.members.sm_p_every', ['what' => $t]),
                    $products === [] => __('admin.members.sm_p_none', ['what' => $t]),
                    default => __('admin.members.sm_in', ['what' => $t, 'names' => implode($comma, $products)]),
                };
            } elseif ($h('catalog.view')) {
                $parts[] = __('admin.members.sm_p_view');
            } else {
                $miss[] = __('admin.members.sm_n_products');
            }
        }
        $media = $join([
            $h('media.upload') ? __('admin.members.sm_m_upload') : null,
            $h('media.folders') ? __('admin.members.sm_m_folders') : null,
            $h('media.delete.any') ? __('admin.members.sm_m_delete_any') : ($h('media.delete.own') ? __('admin.members.sm_m_delete_own') : null),
            $h('media.purge') ? __('admin.members.sm_m_purge') : null,
        ]);
        if ($media !== '') {
            $parts[] = $media;
        } elseif ($h('media.view')) {
            $parts[] = __('admin.members.sm_m_view');
        } else {
            $miss[] = __('admin.members.sm_n_media');
        }
        if ($h('forms.manage')) {
            $parts[] = __('admin.members.sm_f_manage');
        } elseif ($h('forms.view')) {
            $parts[] = __('admin.members.sm_f_view');
        } else {
            $miss[] = __('admin.members.sm_n_forms');
        }
        if ($h('menus.manage')) {
            $parts[] = __('admin.members.sm_menus');
        } else {
            $miss[] = __('admin.members.sm_n_menus');
        }
        if ($h('blocks.manage')) {
            $parts[] = __('admin.members.sm_blocks');
        }
        $users = $join([
            $h('users.manage') ? __('admin.members.sm_u_manage') : ($h('users.invite') ? __('admin.members.sm_u_invite') : null),
            $h('roles.manage') ? __('admin.members.sm_roles') : null,
        ]);
        if ($users !== '') {
            $parts[] = $users;
        } else {
            $miss[] = __('admin.members.sm_n_users');
        }
        $site = $join([
            $h('domains.manage') ? __('admin.members.sm_domains') : null,
            $h('settings.manage') ? __('admin.members.sm_settings') : null,
        ]);
        if ($site !== '') {
            $parts[] = $site;
        } else {
            $miss[] = __('admin.members.sm_n_settings');
        }

        $outside = match (true) {
            $posts !== null && $posts !== [] && $works => __('admin.members.sm_out_posts'),
            $products !== null && $products !== [] && $h('catalog.manage') && $catalogOn => __('admin.members.sm_out_products'),
            default => '',
        };

        return ($parts !== [] ? __('admin.members.sm_does', ['name' => $name, 'list' => implode(__('admin.members.sm_semi'), $parts)]) : __('admin.members.sm_nothing', ['name' => $name]))
            .$outside
            .($miss !== [] ? __('admin.members.sm_cannot', ['list' => implode($comma, $miss)]) : '');
    }

    /**
     * The catalog's tenant permissions by area (the resource each acts on), in AREA_ORDER.
     *
     * @return array<string, list<string>>
     */
    public static function areas(): array
    {
        $byArea = [];
        foreach (PermissionRegistry::TENANT_PERMISSIONS as $permission) {
            $byArea[PermissionRegistry::prefixOf($permission)][] = $permission;
        }
        $ordered = [];
        foreach (self::AREA_ORDER as $area) {
            if (isset($byArea[$area])) {
                $ordered[$area] = $byArea[$area];
                unset($byArea[$area]);
            }
        }

        return $ordered + $byArea;
    }

    /**
     * One permission as the dialog says it.
     *
     * @param  array<string, int>  $has
     * @return array{ok: bool, text: string}|null
     */
    private static function item(string $permission, array $has, bool $catalogOn): ?array
    {
        $can = self::words('admin.members.xc.'.$permission);
        $cannot = self::words('admin.members.xn.'.$permission);
        if ($can === null || $cannot === null) {
            return null;
        }
        $any = self::OWN_IN_ANY[$permission] ?? null;
        $viaAny = $any !== null && ! isset($has[$permission]) && isset($has[$any]);
        if ($viaAny) {
            return ['ok' => true, 'text' => __('admin.members.x_via', ['can' => $can, 'any' => __('admin.members.perm.'.$any)])];
        }
        $ok = isset($has[$permission]);
        if ($ok && $permission === 'taxonomies.manage') {
            // categories of what the role sees: posts with «Nội dung · Xem», products with «Sản phẩm · Xem»
            $posts = isset($has['entries.view']);
            $products = isset($has['catalog.view']) && $catalogOn;
            if ($posts && ! $products) {
                $can = __('admin.members.x_tax_posts');
            } elseif ($products && ! $posts) {
                $can = __('admin.members.x_tax_products');
            }
        }

        return ['ok' => $ok, 'text' => $ok ? $can : $cannot];
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string> those the tenant catalog still has (a retired one kept for a rollback is not counted)
     */
    private static function tenantOnly(array $permissions): array
    {
        return array_values(array_unique(array_intersect($permissions, PermissionRegistry::TENANT_PERMISSIONS)));
    }

    /** The words of $key in the current language, or null when the language files have none. */
    private static function words(string $key): ?string
    {
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }
}
