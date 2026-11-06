<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Admin\Support\GeneralSettingsStore;

/**
 * Which stored values belong to which settings section: what its "Cập nhật lần cuối" line reads
 * and what "Khôi phục mặc định" clears. A section holds any of
 *   settings     — site_settings rows [group, key]; a reset deletes them, so the defaults apply;
 *   profile      — site_profiles columns → the value a reset writes (the column's default);
 *   translations — site_profile_translations columns a reset empties in every language;
 *   brand_keys   — keys the older general.brand setting may still hold for this section (a reset
 *                  removes them, or the storefront would keep showing them).
 *
 * Sections with secrets (email, social sign-in) are not here: a reset must never drop a password a
 * customer cannot see to type back in.
 */
final class SettingsSections
{
    /** @var array<string, array{settings?: list<array{0: string, 1: string}>, profile?: array<string, mixed>, translations?: list<string>, brand_keys?: list<string>}> */
    public const array SECTIONS = [
        'general' => [
            'settings' => [['general', 'timezone'], ['general', 'date_format']],
            'profile' => [
                'brand_name' => null, 'legal_name' => null, 'tax_code' => null, 'representative' => null,
                'business_license_no' => null, 'license_issued_at' => null, 'moit_registered' => false, 'moit_url' => null,
                'phone' => null, 'hotline' => null, 'email' => null, 'address_line' => null,
                'province_id' => null, 'province' => null, 'ward_id' => null, 'ward' => null,
                'social_links' => [], 'business_hours' => null,
            ],
            'translations' => ['brand_name', 'legal_name', 'tagline', 'short_description', 'license_text', 'address_line', 'opening_hours_text'],
            'brand_keys' => GeneralSettingsStore::BRAND_NAP_KEYS,
        ],
        'brand_images' => [
            'profile' => ['logo_media_id' => null, 'logo_dark_media_id' => null, 'favicon_media_id' => null, 'og_image_media_id' => null],
            'brand_keys' => ['logo', 'favicon', 'og_image'],
        ],
        'seo' => ['settings' => [['seo', 'config']]],
        'floating_contact' => ['settings' => [['storefront', 'floating_contact']]],
        'verification' => ['settings' => [['storefront', 'verification']]],
        'catalog' => ['settings' => [['catalog', 'mode']]],
        'admin_theme' => ['settings' => [['admin_theme', 'config']]],
    ];

    /** The profile columns that hold a media id (stored by media key in an undo payload). */
    public const array MEDIA_COLUMNS = ['logo_media_id', 'logo_dark_media_id', 'favicon_media_id', 'og_image_media_id'];

    /**
     * @return array{settings: list<array{0: string, 1: string}>, profile: array<string, mixed>, translations: list<string>, brand_keys: list<string>}
     *
     * @throws SettingsChangeRejected
     */
    public static function get(string $section): array
    {
        $definition = self::SECTIONS[$section] ?? throw new SettingsChangeRejected('section_invalid', $section);

        return [
            'settings' => $definition['settings'] ?? [],
            'profile' => $definition['profile'] ?? [],
            'translations' => $definition['translations'] ?? [],
            'brand_keys' => $definition['brand_keys'] ?? [],
        ];
    }
}
