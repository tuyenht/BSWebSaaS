<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Theming\HeadingDecor;
use Illuminate\Support\Facades\Gate;

/**
 * Saves the site-wide decoration under section headings (site setting `theme.heading_decor`, read by
 * HeadingDecor). Every field is checked against the lists the storefront draws from: a shape that is
 * not one of the platform's, a colour that is not a hex value or a palette step, a width or weight
 * that is not listed, or a mask picture that is not a PNG of this site's library (looked up by media
 * key through the site's scope, so another site's file or one in the trash is refused) throws a
 * SettingsChangeRejected and nothing is written. The shape may be left out (a mask picture alone is a
 * valid setting); a save replaces the whole setting, so a shape saved after a mask leaves no mask. An empty input removes the setting, which brings back
 * the short rule headings have always had.
 *
 * Behind the settings permission, in the site's settings transaction (SettingsWrite).
 */
final class SetHeadingDecor
{
    /** The fields a save may carry. */
    private const array FIELDS = ['style', 'color', 'custom_color', 'gradient', 'custom_color_2', 'align', 'width', 'weight', 'mask_media', 'mask_keep_color'];

    /**
     * @param  array<string, mixed>  $input  empty removes the setting
     *
     * @throws SettingsChangeRejected
     */
    public function handle(array $input): void
    {
        Gate::authorize('settings.manage');
        $value = $this->checked($input);

        SettingsWrite::run('heading_decor', function () use ($value): void {
            $row = SiteSetting::query()->where('group_name', 'theme')->where('key', 'heading_decor')->lockForUpdate()->first();
            if ($value === null) {
                $row?->delete();

                return;
            }

            // The picture is looked up with the site's lock held and its row locked FOR KEY SHARE, so a
            // purge that removed it first is seen here and one that comes after waits for this save.
            if ($value['mask_media'] !== ''
                && ! Media::query()->where('storage_key', $value['mask_media'])->whereIn('mime', HeadingDecor::MASK_MIMES)->lock('for key share')->exists()) {
                throw new SettingsChangeRejected('image_invalid', 'mask_media');
            }

            SiteSetting::query()->updateOrCreate(['group_name' => 'theme', 'key' => 'heading_decor'], ['value' => $value]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{style: string, color: string, custom_color: string, gradient: bool, custom_color_2: string, align: string, width: string, weight: string, mask_media: string, mask_keep_color: bool}|null
     *
     * @throws SettingsChangeRejected
     */
    private function checked(array $input): ?array
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
            'style' => $this->style($input),
            'color' => $this->listed($input, 'color', HeadingDecor::COLORS, 'accent'),
            'custom_color' => $this->hex($input, 'custom_color'),
            'gradient' => $this->flag($input, 'gradient'),
            'custom_color_2' => $this->hex($input, 'custom_color_2'),
            'align' => $this->listed($input, 'align', HeadingDecor::ALIGNS, 'title'),
            'width' => $this->listed($input, 'width', HeadingDecor::WIDTHS, 'medium'),
            'weight' => $this->listed($input, 'weight', HeadingDecor::WEIGHTS, 'medium'),
            'mask_media' => $this->maskKey($input),
            'mask_keep_color' => $this->flag($input, 'mask_keep_color'),
        ];

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
    private function style(array $input): string
    {
        $given = $input['style'] ?? '';

        return $given === '' ? '' : $this->listed($input, 'style', HeadingDecor::SITE_STYLES, '');
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $options
     */
    private function listed(array $input, string $field, array $options, string $default): string
    {
        $given = $input[$field] ?? $default;
        if (! is_string($given) || ! in_array($given, $options, true)) {
            throw new SettingsChangeRejected($field === 'color' ? 'color_invalid' : 'option_invalid', $field);
        }

        return $given;
    }

    /** @param  array<string, mixed>  $input */
    private function hex(array $input, string $field): string
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
    private function flag(array $input, string $field): bool
    {
        $given = $input[$field] ?? false;
        if (! is_bool($given)) {
            throw new SettingsChangeRejected('option_invalid', $field);
        }

        return $given;
    }

    /** @param  array<string, mixed>  $input */
    private function maskKey(array $input): string
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
