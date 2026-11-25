<?php

declare(strict_types=1);

namespace App\Modules\Admin\Theme;

use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;

/**
 * Resolves the admin chrome theme for the current request: app default → site default
 * (site_settings group `admin_theme` — NEVER the public storefront `theme` group) →
 * per-user override (users.preferences.theme). Each field merges independently, so a
 * tier may pin one axis and inherit the rest.
 *
 * Values are allow-listed on WRITE (settings page / preference endpoint) AND here on
 * READ, so a row written by any other path can only fall back to the default — the
 * output is rendered into `data-*` attributes on <html>.
 *
 * Stateless on purpose (Octane): no cross-request memo; the layout composers call it
 * once per request. The platform console has no tenant context (no SetCurrentSite on
 * that route group), so the site tier is skipped there. Its plane emits only the
 * PLATFORM_FIELDS axes: the scheme, plus the sidebar size the console's menu button
 * toggles (one per-user preference shared with the tenant admin).
 */
final class AdminChromeResolver
{
    /** Allowed values per axis; the first entry is the app default (today's look). */
    public const array FIELDS = [
        'scheme' => ['light', 'dark'],
        'sidebar' => ['dark', 'light', 'gradient', 'gradient-2', 'gradient-3', 'gradient-4'],
        // auto = the brand fill below 1024px (phone and tablet), the light bar from 1024px.
        'topbar' => ['auto', 'light', 'dark'],
        'position' => ['fixed', 'scrollable'],
        'layout' => ['vertical'], // horizontal + two-column join once their nav templates exist
        'layout_width' => ['fluid', 'boxed'],
        'sidebar_size' => ['lg', 'sm'],
        'sidebar_image' => ['none', 'img-1', 'img-2', 'img-3', 'img-4'],
        // Per-user text size (My account → Cỡ chữ): md is today's size; each step adds 2px to
        // body text through --user-text-boost (design-tokens §2.4).
        'text_size' => ['md', 'lg', 'xl', 'xxl'],
        // Per-user motion (My account → Giảm chuyển động): auto follows the device setting;
        // reduce stops transitions and animations in the admin whatever the device says.
        'motion' => ['auto', 'reduce'],
        // Per-user contrast (My account → Tương phản cao): high darkens text and borders through the
        // contrast token set (admin-chrome.css). The member's own choice only, never a site default;
        // with no choice the device's "more contrast" setting decides (CONTRAST_AUTO_ATTRIBUTE).
        'contrast' => ['normal', 'high'],
    ];

    /** Axis → the <html> data-attribute it drives (Velzon/Bootstrap 5.3 names). */
    public const array ATTRIBUTES = [
        'scheme' => 'data-bs-theme',
        'sidebar' => 'data-sidebar',
        'topbar' => 'data-topbar',
        'position' => 'data-layout-position',
        'layout' => 'data-layout',
        'layout_width' => 'data-layout-width',
        'sidebar_size' => 'data-sidebar-size',
        'sidebar_image' => 'data-sidebar-image',
        'text_size' => 'data-text-size',
        'motion' => 'data-motion',
        'contrast' => 'data-contrast',
    ];

    /**
     * "Follow the device" for light/dark (Theo máy). Stored as scheme = 'auto' on the member's
     * own preferences (My account) or on the site default (Giao diện trang quản trị); never a
     * resolved value: the server still renders a light/dark scheme into data-bs-theme and adds
     * this flag, and theme-boot swaps in the device's light/dark before paint. A member's own
     * light or dark pick wins over a site's 'auto'.
     */
    public const string SCHEME_AUTO = 'auto';

    public const string SCHEME_AUTO_ATTRIBUTE = 'data-scheme-auto';

    /**
     * Axes only a member sets for themselves: the site tier never pins them (the Appearance
     * settings page leaves them out), so they always read app default → the member's choice.
     */
    public const array USER_ONLY_FIELDS = ['contrast'];

    /**
     * A member who has not chosen a contrast follows the device: the server renders the default
     * into data-contrast with this flag, and theme-boot swaps in high before paint when the
     * device asks for more contrast (prefers-contrast: more), and again on every OS switch.
     */
    public const string CONTRAST_AUTO_ATTRIBUTE = 'data-contrast-auto';

    /**
     * The axes the platform console renders. Its sidebar, topbar and position never follow
     * tenant-admin variants; the sidebar size is the one exception — the console's menu
     * button collapses its sidebar to the icon rail from 1280px, using the same per-user
     * preference as the tenant shell. The text size, the motion and the contrast settings are a person's needs, so they follow them too.
     */
    public const array PLATFORM_FIELDS = ['scheme', 'sidebar_size', 'text_size', 'motion', 'contrast'];

    /** @var array<array-key, mixed>|null the site tier, read once per instance (built per use, never shared across requests) */
    private ?array $site = null;

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * The site default as stored (site_settings `admin_theme`), unfiltered.
     *
     * @return array<array-key, mixed>
     */
    public function siteDefaults(): array
    {
        return $this->site ??= Cast::arr($this->settings->get('admin_theme'));
    }

    /**
     * The axes the current plane renders onto <html> (and theme-boot re-applies).
     *
     * @return list<string>
     */
    public static function planeFields(): array
    {
        return AdminPlane::isPlatform() ? self::PLATFORM_FIELDS : array_keys(self::FIELDS);
    }

    /**
     * The axes a site may pin as its default (the Appearance settings page): every axis but the
     * member-only ones.
     *
     * @return array<string, list<string>>
     */
    public static function siteFields(): array
    {
        $fields = array_diff_key(self::FIELDS, array_flip(self::USER_ONLY_FIELDS));
        $fields['scheme'][] = self::SCHEME_AUTO;

        return $fields;
    }

    /**
     * @return array<string, string> field => resolved value (always complete)
     */
    public function resolve(?User $user): array
    {
        $resolved = array_map(fn (array $allowed): string => $allowed[0], self::FIELDS);

        if (! AdminPlane::isPlatform()) {
            $resolved = $this->overlay($resolved, array_diff_key($this->siteDefaults(), array_flip(self::USER_ONLY_FIELDS)));
        }

        return $this->overlay($resolved, Cast::arr($user?->preferences['theme'] ?? null));
    }

    /**
     * The `data-*` attributes the current plane's layout SSRs onto <html>. The platform
     * console renders only PLATFORM_FIELDS: its sidebar/topbar/position must not follow
     * tenant-admin variants.
     *
     * @return array<string, string> attribute name => value
     */
    public function attributes(?User $user): array
    {
        $resolved = $this->resolve($user);
        $attributes = [];
        foreach (self::planeFields() as $field) {
            $attributes[self::ATTRIBUTES[$field]] = $resolved[$field];
        }
        if ($this->followsDeviceFor($user)) {
            $attributes[self::SCHEME_AUTO_ATTRIBUTE] = 'true';
        }
        if (! self::choseContrast($user)) {
            $attributes[self::CONTRAST_AUTO_ATTRIBUTE] = 'true';
        }

        return $attributes;
    }

    /** Whether the member picked a contrast of their own (else the device decides). */
    public static function choseContrast(?User $user): bool
    {
        return in_array(Cast::arr($user?->preferences['theme'] ?? null)['contrast'] ?? null, self::FIELDS['contrast'], true);
    }

    /**
     * Whether light/dark follows the device for this member: their own 'auto', or, when they
     * pinned neither light nor dark, the site default's 'auto' (tenant admin only: the platform
     * console has no site tier).
     */
    public function followsDeviceFor(?User $user): bool
    {
        $own = Cast::arr($user?->preferences['theme'] ?? null)['scheme'] ?? null;
        if ($own === self::SCHEME_AUTO) {
            return true;
        }
        if (in_array($own, self::FIELDS['scheme'], true) || AdminPlane::isPlatform()) {
            return false;
        }

        return ($this->siteDefaults()['scheme'] ?? null) === self::SCHEME_AUTO;
    }

    /** Whether the member chose "follow the device" for light/dark themselves. */
    public static function followsDevice(?User $user): bool
    {
        return (Cast::arr($user?->preferences['theme'] ?? null)['scheme'] ?? null) === self::SCHEME_AUTO;
    }

    /**
     * Merge one tier over the base, field by field, ignoring anything outside the
     * allow-list (fail-safe read).
     *
     * @param  array<string, string>  $base
     * @param  array<mixed>  $tier
     * @return array<string, string>
     */
    private function overlay(array $base, array $tier): array
    {
        foreach (self::FIELDS as $field => $allowed) {
            $value = $tier[$field] ?? null;
            if (is_string($value) && in_array($value, $allowed, true)) {
                $base[$field] = $value;
            }
        }

        return $base;
    }
}
