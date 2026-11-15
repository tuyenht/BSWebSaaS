<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\StorefrontChrome;
use Illuminate\Support\Facades\Gate;

/**
 * Sets the phone menu's colour tone (`drawer_tone`) and accent (`drawer_accent`), two keys of the
 * site's `storefront.header` setting, from "Giao diện website". The row is locked while the two keys
 * change and every other key is kept as stored (the top bar, the products tab, the text size…), so a
 * header saved meanwhile is not overwritten. A site without a header setting has no phone menu to
 * colour, and creating the row here would switch its plain header for the rich one: nothing is
 * written then (false). A value outside the allowed lists is refused (choice_invalid) before any write.
 * The site profile row is touched so the section's "last saved" line follows.
 *
 * Behind the settings permission; one transaction (SettingsWrite: the site's settings lock first,
 * waited for a few seconds at most, else a busy refusal).
 */
final class SetPhoneMenuStyle
{
    /**
     * @return bool whether the site has a header setting to write into
     *
     * @throws SettingsChangeRejected
     */
    public function handle(string $tone, string $accent): bool
    {
        Gate::authorize('settings.manage');
        if (! in_array($tone, StorefrontChrome::DRAWER_TONES, true)) {
            throw new SettingsChangeRejected('choice_invalid', 'tone');
        }
        if (! in_array($accent, StorefrontChrome::DRAWER_ACCENTS, true)) {
            throw new SettingsChangeRejected('choice_invalid', 'accent');
        }

        return SettingsWrite::run('appearance', function () use ($tone, $accent): bool {
            $row = SiteSetting::query()->where('group_name', 'storefront')->where('key', 'header')->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            $value = Cast::arr($row->getAttribute('value'));
            $next = [...$value, 'drawer_tone' => $tone, 'drawer_accent' => $accent];
            if ($next !== $value) {
                $row->setAttribute('value', $next);
                $row->save();
                SiteProfile::query()->first()?->touch();
            }

            return true;
        });
    }
}
