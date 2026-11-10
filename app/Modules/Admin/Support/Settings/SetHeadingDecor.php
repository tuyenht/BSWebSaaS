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
            if ($value['mask_media'] !== ''
                && ! Media::query()->where('storage_key', $value['mask_media'])->whereIn('mime', HeadingDecor::MASK_MIMES)->lock('for key share')->exists()) {
                throw new SettingsChangeRejected('image_invalid', 'mask_media');
            }

            SiteSetting::query()->updateOrCreate(['group_name' => 'theme', 'key' => 'heading_decor'], ['value' => $value]);
        });
    }
}
