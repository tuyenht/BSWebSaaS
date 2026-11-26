<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Theming\HeadingDecor;
use Illuminate\Support\Facades\Gate;

/**
 * Saves the site-wide decoration under section headings (site setting `theme.heading_decor`, read by
 * HeadingDecor). Every field is checked against the lists the storefront draws from: a shape that is
 * not one of the platform's, a colour that is not a hex value or a palette step, a width or weight
 * that is not listed, or a mask picture that is not a PNG of this site's library (named by its row
 * ulid, or by its storage key from a screen opened before pictures were named by row; looked up
 * through the site's scope, so another site's file or one in the trash is refused) throws a
 * SettingsChangeRejected and nothing is written. Either way the picture is stored by its row's own
 * ulid (`mask_ulid`, never the value as sent) and the older key field is left out. The shape may be
 * left out (a mask picture alone is a valid setting); a save replaces the whole setting, so a shape
 * saved after a mask leaves no mask. An empty input removes the setting, which brings back the short
 * rule headings have always had. The mask's "in use" row (HeadingDecorInput::syncMaskUsage) is
 * written in the same transaction.
 *
 * Behind the settings permission, in the site's settings transaction (SettingsWrite).
 */
final class SetHeadingDecor
{
    /**
     * @param  array<string, mixed>  $input  empty removes the setting
     *
     * @throws SettingsChangeRejected
     */
    public function handle(array $input): void
    {
        Gate::authorize('settings.manage');
        $value = HeadingDecorInput::checked($input);

        SettingsWrite::run('heading_decor', function () use ($value): void {
            $row = SiteSetting::query()->where('group_name', 'theme')->where('key', 'heading_decor')->lockForUpdate()->first();
            if ($value === null) {
                $row?->delete();

                return;
            }

            // The picture is looked up with the site's lock held and its row locked FOR KEY SHARE, so a
            // purge that removed it first is seen here and one that comes after waits for this save.
            $ulid = $value['mask_ulid'] ?? '';
            $field = $ulid !== '' ? 'mask_ulid' : 'mask_media';
            $mask = null;
            if ($ulid !== '' || $value['mask_media'] !== '') {
                $mask = Media::query()
                    ->where($ulid !== '' ? 'ulid' : 'storage_key', $ulid !== '' ? $ulid : $value['mask_media'])
                    ->whereIn('mime', HeadingDecor::MASK_MIMES)
                    ->lock('for key share')
                    ->value('ulid');
                if (! is_string($mask)) {
                    throw new SettingsChangeRejected('image_invalid', $field);
                }
            }

            $stored = [];
            foreach ($value as $name => $given) {
                if ($name === 'mask_media') {
                    $stored['mask_ulid'] = $mask ?? '';
                } elseif ($name !== 'mask_ulid') {
                    $stored[$name] = $given;
                }
            }

            $setting = SiteSetting::query()->updateOrCreate(['group_name' => 'theme', 'key' => 'heading_decor'], ['value' => $stored]);
            HeadingDecorInput::syncMaskUsage(Cast::int($setting->getKey()), $stored);
        });
    }
}
