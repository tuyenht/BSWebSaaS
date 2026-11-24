<?php

declare(strict_types=1);

namespace App\Modules\Admin\Navigation;

use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Support\SiteOwner;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

/**
 * The settings sections the signed-in member may open, in the groups of the design board
 * («Khung quản trị», settings pane): Chung · Nội dung · Tiếp thị & Kết nối · Hệ thống · Nâng cao
 * (folded until opened), each item with its icon and its one-line description (the menu's search
 * also matches it). The member's own «Bảo mật» stays in a «Tài khoản của tôi» group ahead of them.
 * The settings frame draws this list; the main menu's «Cài đặt» opens it through entryUrl().
 * Hiding an item is navigation only: every route carries its own `can:` check.
 */
final class SettingsMenu
{
    /** The address ending that opens the frame as the list of sections below 768px (admin-settings-nav.js). */
    public const LIST_HASH = '#settings-menu';

    /**
     * `$active` is the section the frame shows (its key): that section stays in the menu even where
     * the menu would leave it out, decided from the page and not from the request's route, which on
     * a Livewire round trip is the update endpoint.
     *
     * @return list<array{key: string, label: string, fold: bool, items: list<array{key: string, route: string, label: string, icon: string, desc: string}>}>
     */
    public static function groups(string $active = ''): array
    {
        $groups = AdminPlane::isPlatform() ? self::platform() : self::site($active);

        return array_values(array_filter($groups, static fn (array $group): bool => $group['items'] !== []));
    }

    /**
     * Where the main menu's «Cài đặt» (and /admin/settings) leads: the first site-wide section,
     * opened on phones as the list of sections (LIST_HASH); a member who manages no site-wide
     * setting goes to Tên miền when that is theirs to read, else to their own «Bảo mật».
     */
    public static function entryUrl(bool $manage, bool $domains): string
    {
        if ($manage && Route::has('admin.settings.general')) {
            return route('admin.settings.general').self::LIST_HASH;
        }
        if ($domains && Route::has('admin.settings.domains')) {
            return route('admin.settings.domains');
        }

        return route('admin.account', ['tab' => 'security']);
    }

    /** entryUrl() for the signed-in member (the /admin/settings address). */
    public static function entryUrlForMember(): string
    {
        return self::entryUrl(
            Gate::allows('settings.manage'),
            Gate::allows('domains.view') && in_array('domains', app(Entitlement::class)->entitledModuleKeys(), true),
        );
    }

    /**
     * @return list<array{key: string, label: string, fold: bool, items: list<array{key: string, route: string, label: string, icon: string, desc: string}>}>
     */
    private static function platform(): array
    {
        return [
            self::group('ops', [
                self::item('system-email', 'platform.settings.email', 'admin.system_email', 'ri-mail-send'),
                self::item('system-social', 'platform.settings.social', 'admin.system_social', 'ri-shield-user'),
            ]),
            self::group('advanced', [
                self::item('login-security', 'platform.settings.security', 'admin.system_login_security', 'ri-lock-2'),
            ], fold: true),
        ];
    }

    /**
     * @return list<array{key: string, label: string, fold: bool, items: list<array{key: string, route: string, label: string, icon: string, desc: string}>}>
     */
    private static function site(string $active): array
    {
        $groups = [self::group('account', [self::item('security', 'admin.account', 'admin.security', 'ri-lock-password')])];

        // Site-wide settings only for holders of `settings.manage` (owner/admin/super_admin).
        $manage = Gate::allows('settings.manage');
        // Tên miền reads with domains.view alone (changes need domains.manage on the page), on a site
        // entitled to its own domains — entitlement before RBAC, as the main menu does. The page itself
        // refuses a site that is not entitled, so there it is not asked twice.
        $domains = Gate::allows('domains.view') && ($active === 'domains'
            || in_array('domains', app(Entitlement::class)->entitledModuleKeys(), true));
        // Bảo mật & Nâng cao is in the menu for the owner only (maintenance and the other switches
        // only the owner changes); another holder of settings.manage who opens the address reads it.
        $advanced = $manage && ($active === 'advanced' || SiteOwner::is(auth()->user()));
        // The approval-workflow switchboard is for platform operators only (super_admin enables
        // approval per site × content type); no tenant role carries this permission.
        $workflow = $manage && Gate::allows('platform.workflows.manage');

        $groups[] = self::group('general', $manage ? [
            self::item('general', 'admin.settings.general', 'admin.gs.title', 'ri-information-2'),
            self::item('appearance', 'admin.settings.appearance', 'admin.website_look', 'ri-palette'),
            self::item('admin-ui', 'admin.settings.admin-ui', 'admin.admin_ui', 'ri-layout-4'),
            self::item('langs', 'admin.settings.languages', 'admin.lng.title', 'ri-translate-2'),
        ] : []);
        $groups[] = self::group('content', $manage ? [
            self::item('catalog', 'admin.settings.catalog', 'admin.catalog_settings', 'ri-box-3'),
        ] : []);
        $groups[] = self::group('marketing', $manage ? [
            self::item('seo', 'admin.settings.seo', 'admin.seoset.title', 'ri-search-eye'),
            self::item('redirects', 'admin.settings.redirects', 'admin.rd.title', 'ri-route'),
            self::item('integrations', 'admin.settings.integrations', 'admin.int.title', 'ri-plug-2'),
            self::item('floating', 'admin.settings.floating-contact', 'admin.fc.title', 'ri-customer-service-2'),
        ] : []);
        $groups[] = self::group('system', [
            $domains ? self::item('domains', 'admin.settings.domains', 'admin.domains', 'ri-global') : null,
            $manage ? self::item('email', 'admin.settings.email', 'admin.email_settings', 'ri-mail-send') : null,
            $manage ? self::item('social', 'admin.settings.social', 'admin.social_login', 'ri-shield-user') : null,
        ]);
        $groups[] = self::group('advanced', [
            $advanced ? self::item('advanced', 'admin.settings.advanced', 'admin.adv.title', 'ri-lock-2') : null,
            $workflow ? self::item('workflow', 'admin.settings.workflows', 'admin.workflow_settings', 'ri-git-branch') : null,
        ], fold: true);

        return $groups;
    }

    /**
     * @param  list<array{key: string, route: string, label: string, icon: string, desc: string}|null>  $items
     * @return array{key: string, label: string, fold: bool, items: list<array{key: string, route: string, label: string, icon: string, desc: string}>}
     */
    private static function group(string $key, array $items, bool $fold = false): array
    {
        $label = $key === 'account' ? __('admin.settings_account') : __('admin.set.g.'.$key);

        return ['key' => $key, 'label' => $label, 'fold' => $fold, 'items' => array_values(array_filter($items))];
    }

    /**
     * An item whose route exists (a section not built on this install is left out).
     *
     * @return array{key: string, route: string, label: string, icon: string, desc: string}|null
     */
    private static function item(string $key, string $route, string $labelKey, string $icon): ?array
    {
        if (! Route::has($route)) {
            return null;
        }

        return [
            'key' => $key,
            'route' => $route,
            'label' => __($labelKey),
            'icon' => $icon,
            'desc' => Lang::has('admin.set.sd.'.$key) ? __('admin.set.sd.'.$key) : '',
        ];
    }
}
