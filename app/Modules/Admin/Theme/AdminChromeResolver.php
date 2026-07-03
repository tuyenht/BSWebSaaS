<?php

declare(strict_types=1);

namespace App\Modules\Admin\Theme;

use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Support\Cast;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;

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
 * that route group), so the site tier is skipped there — which also keeps the plane
 * scheme-only by construction.
 */
final readonly class AdminChromeResolver
{
    /** Allowed values per axis; the first entry is the app default (today's look). */
    public const array FIELDS = [
        'scheme' => ['light', 'dark'],
        'sidebar' => ['dark', 'light', 'gradient', 'gradient-2', 'gradient-3', 'gradient-4'],
        'topbar' => ['light', 'dark'],
        'position' => ['fixed', 'scrollable'],
        'layout' => ['vertical'], // horizontal + two-column join once their nav templates exist
        'layout_width' => ['fluid', 'boxed'],
        'sidebar_size' => ['lg', 'sm'],
        'sidebar_image' => ['none', 'img-1', 'img-2', 'img-3', 'img-4'],
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
    ];

    public function __construct(private SettingsRepository $settings) {}

    /**
     * @return array<string, string> field => resolved value (always complete)
     */
    public function resolve(?User $user): array
    {
        $resolved = array_map(fn (array $allowed): string => $allowed[0], self::FIELDS);

        if (! AdminPlane::isPlatform()) {
            $resolved = $this->overlay($resolved, $this->settings->get('admin_theme') ?? []);
        }

        return $this->overlay($resolved, Cast::arr($user?->preferences['theme'] ?? null));
    }

    /**
     * The `data-*` attributes the current plane's layout SSRs onto <html>. The platform
     * console is a scheme-only plane: it has no sidebar and its topbar/position must not
     * follow tenant-admin variants.
     *
     * @return array<string, string> attribute name => value
     */
    public function attributes(?User $user): array
    {
        $resolved = $this->resolve($user);
        $fields = AdminPlane::isPlatform() ? ['scheme'] : array_keys(self::FIELDS);

        $attributes = [];
        foreach ($fields as $field) {
            $attributes[self::ATTRIBUTES[$field]] = $resolved[$field];
        }

        return $attributes;
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
