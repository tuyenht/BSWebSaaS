<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sets the site's brand images (logo, logo on a dark background, favicon, share image) from the
 * media keys the settings screen sends — never a row id. Each key is looked up through the site's
 * media scope (BelongsToSite, RLS underneath), so another site's picture, one in the trash or a
 * file that is not a picture is refused (image_invalid) and nothing is written. An empty key
 * removes that image.
 *
 * The images live on the site profile as media ids. The older general.brand setting still holds a
 * URL for logo, favicon and share image on sites not yet saved here; saving a slot removes its URL
 * from that setting, so from then on the id is the one source (an image removed here stays
 * removed). Slots the call does not name keep what they have, URL included.
 *
 * Behind the settings permission. One transaction; the profile row is written in every save that
 * changes something (RecordsWhoSaved stamps who), so the section's "last saved" line follows.
 */
final class SetBrandImages
{
    /** The slots whose older URL general.brand may still hold. */
    private const array LEGACY_SLOTS = ['logo', 'favicon', 'og_image'];

    /**
     * @param  array<string, string|null>  $picks  slot → media key ('' or null removes the image)
     *
     * @throws SettingsChangeRejected
     */
    public function handle(array $picks): void
    {
        Gate::authorize('settings.manage');
        if ($picks === []) {
            return;
        }

        $ids = [];
        $wanted = [];
        foreach ($picks as $slot => $key) {
            if (! array_key_exists($slot, SiteProfile::IMAGE_COLUMNS)) {
                throw new SettingsChangeRejected('slot_invalid', $slot);
            }
            $key = trim((string) $key);
            if ($key === '') {
                $ids[$slot] = null;
            } else {
                $wanted[$slot] = $key;
            }
        }

        if ($wanted !== []) {
            $found = Media::query()
                ->whereIn('storage_key', array_values(array_unique($wanted)))
                ->where('mime', 'like', 'image/%')
                ->orderBy('id')
                ->get(['id', 'storage_key'])
                ->unique('storage_key')
                ->pluck('id', 'storage_key');
            foreach ($wanted as $slot => $key) {
                $id = $found->get($key);
                $ids[$slot] = $id === null ? throw new SettingsChangeRejected('image_invalid', $slot) : Cast::int($id);
            }
        }

        DB::transaction(function () use ($ids): void {
            // The brand row is written back whole: lock it first, then read it, so a save running
            // at the same time (here, the general section, a reset) waits instead of being undone.
            $brandRow = SiteSetting::query()->where('group_name', 'general')->where('key', 'brand')->lockForUpdate()->first();

            $profile = SiteProfile::query()->firstOrNew();
            foreach ($ids as $slot => $id) {
                $profile->setAttribute(SiteProfile::IMAGE_COLUMNS[$slot], $id);
            }

            $brand = Cast::arr($brandRow?->value);   // a value that is not a list of keys holds no URL
            $legacy = array_flip(array_intersect(array_keys($ids), self::LEGACY_SLOTS));
            $legacyRemoved = false;
            if ($brandRow !== null && array_intersect_key($brand, $legacy) !== []) {
                $brandRow->value = array_diff_key($brand, $legacy);
                $brandRow->save();
                $legacyRemoved = true;
            }

            if ($profile->isDirty() || ! $profile->exists) {
                $profile->save();
            } elseif ($legacyRemoved) {
                $profile->touch();   // the image the site shows changed: the section's stamp follows
            }
        });
    }
}
