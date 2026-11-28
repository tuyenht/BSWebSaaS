<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Shared\Support\Cast;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Theming\HeadingDecor;

/**
 * The one check of the site-wide heading decoration (`theme.heading_decor`), run by the admin's save
 * (SetHeadingDecor) and by the content-pack loader's validator: every field against the lists the
 * storefront draws from, a colour that is a hex value or a palette step, a width or weight that is
 * listed. It looks at the input only (no database), so the picture the mask names (`mask_ulid`, its
 * library row; `mask_media`, its storage key, from a screen opened before pictures were named by
 * row) is looked up by the caller. A mask named both ways is refused. Empty input means "remove the
 * setting" (null).
 *
 * syncMaskUsage() writes the library's "in use" row of the mask picture (`mediables`, owner the
 * setting row `site_setting`, collection MASK_COLLECTION), in the caller's transaction, so a picture
 * the headings draw is never listed as unused or offered for purging.
 */
final class HeadingDecorInput
{
    /** The collection of the library's "in use" rows the heading mask picture is recorded under. */
    public const string MASK_COLLECTION = 'heading_mask';

    /** The fields a save may carry. */
    private const array FIELDS = ['style', 'color', 'custom_color', 'gradient', 'custom_color_2', 'align', 'width', 'weight', 'mask_media', 'mask_ulid', 'mask_keep_color'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{style: string, color: string, custom_color: string, gradient: bool, custom_color_2: string, align: string, width: string, weight: string, mask_media: string, mask_keep_color: bool, mask_ulid?: string}|null
     *
     * @throws SettingsChangeRejected
     */
    public static function checked(array $input): ?array
    {
        if ($input === []) {
            return null;
        }
        foreach (array_keys($input) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                throw new SettingsChangeRejected('field_invalid', (string) $field);
            }
        }

        $value = [
            'style' => self::style($input),
            'color' => self::listed($input, 'color', HeadingDecor::COLORS, 'accent'),
            'custom_color' => self::hex($input, 'custom_color'),
            'gradient' => self::flag($input, 'gradient'),
            'custom_color_2' => self::hex($input, 'custom_color_2'),
            'align' => self::listed($input, 'align', HeadingDecor::ALIGNS, 'title'),
            'width' => self::listed($input, 'width', HeadingDecor::WIDTHS, 'medium'),
            'weight' => self::listed($input, 'weight', HeadingDecor::WEIGHTS, 'medium'),
            'mask_media' => self::maskKey($input),
            'mask_keep_color' => self::flag($input, 'mask_keep_color'),
        ];
        // Only when given: a value written without it (a content pack's) keeps the shape it always had.
        if (array_key_exists('mask_ulid', $input)) {
            $value['mask_ulid'] = self::maskUlid($input);
            if ($value['mask_ulid'] !== '' && $value['mask_media'] !== '') {
                throw new SettingsChangeRejected('image_invalid', 'mask_ulid');
            }
        }

        if ($value['color'] === 'custom' && $value['custom_color'] === '') {
            throw new SettingsChangeRejected('color_invalid', 'custom_color');
        }
        if ($value['color'] === 'custom' && $value['gradient'] && $value['custom_color_2'] === '') {
            throw new SettingsChangeRejected('color_invalid', 'custom_color_2');
        }

        return $value;
    }

    /**
     * The shape is optional: left out (or empty) it keeps the plain rule headings always had, and a mask
     * picture, when one is set, is what gets drawn.
     *
     * @param  array<string, mixed>  $input
     */
    private static function style(array $input): string
    {
        $given = $input['style'] ?? '';

        return $given === '' ? '' : self::listed($input, 'style', HeadingDecor::SITE_STYLES, '');
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $options
     */
    private static function listed(array $input, string $field, array $options, string $default): string
    {
        $given = $input[$field] ?? $default;
        if (! is_string($given) || ! in_array($given, $options, true)) {
            throw new SettingsChangeRejected($field === 'color' ? 'color_invalid' : 'option_invalid', $field);
        }

        return $given;
    }

    /** @param  array<string, mixed>  $input */
    private static function hex(array $input, string $field): string
    {
        $given = $input[$field] ?? '';
        if ($given === '') {
            return '';
        }
        if (! is_string($given) || preg_match(HeadingDecor::HEX, $given) !== 1) {
            throw new SettingsChangeRejected('color_invalid', $field);
        }

        return strtolower($given);
    }

    /** @param  array<string, mixed>  $input */
    private static function flag(array $input, string $field): bool
    {
        $given = $input[$field] ?? false;
        if (! is_bool($given)) {
            throw new SettingsChangeRejected('option_invalid', $field);
        }

        return $given;
    }

    /**
     * Make the setting row's "in use" row match the mask picture it names: by its row ulid, else (a
     * setting saved before pictures were named by row) by its storage key. A row for a picture no
     * longer named is removed, a missing one added (idempotent). A value that names no PNG of this
     * site is skipped; one in the trash keeps its row (restored, the headings draw it again). One
     * lookup, site-scoped, only when a picture is named; runs inside the caller's transaction.
     */
    public static function syncMaskUsage(int $settingId, mixed $value): void
    {
        $site = HeadingDecor::clean($value);
        $mediaId = null;
        if ($site['mask_ulid'] !== '' || $site['mask_media'] !== '') {
            $found = Media::withTrashed()
                ->when($site['mask_ulid'] !== '', fn ($query) => $query->where('ulid', $site['mask_ulid']), fn ($query) => $query->where('storage_key', $site['mask_media']))
                ->whereIn('mime', HeadingDecor::MASK_MIMES)
                ->value('id');
            $mediaId = $found === null ? null : Cast::int($found);
        }

        Mediable::query()
            ->where('mediable_type', 'site_setting')
            ->where('mediable_id', $settingId)
            ->where('collection', self::MASK_COLLECTION)
            ->when($mediaId !== null, fn ($query) => $query->where('media_id', '!=', $mediaId))
            ->delete();
        if ($mediaId !== null) {
            Mediable::query()->createOrFirst([
                'media_id' => $mediaId,
                'mediable_type' => 'site_setting',
                'mediable_id' => $settingId,
                'collection' => self::MASK_COLLECTION,
            ]);
        }
    }

    /** @param  array<string, mixed>  $input */
    private static function maskUlid(array $input): string
    {
        $given = $input['mask_ulid'] ?? '';
        if ($given === '') {
            return '';
        }
        if (! is_string($given) || preg_match(ButtonLink::ULID_PATTERN, $given) !== 1) {
            throw new SettingsChangeRejected('image_invalid', 'mask_ulid');
        }

        return strtoupper($given);
    }

    /** @param  array<string, mixed>  $input */
    private static function maskKey(array $input): string
    {
        $given = $input['mask_media'] ?? '';
        if ($given === '') {
            return '';
        }
        if (! is_string($given) || preg_match('~^[A-Za-z0-9._/-]{1,255}$~D', $given) !== 1) {
            throw new SettingsChangeRejected('image_invalid', 'mask_media');
        }

        return $given;
    }
}
