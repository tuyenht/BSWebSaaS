<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteProfileTranslation;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Khôi phục mặc định" for one settings section (SettingsSections), and its "Hoàn tác".
 *
 * reset() deletes the section's site_settings rows (the defaults apply), writes each profile field
 * its default (empty), empties the section's per-language profile text and removes the keys the
 * older general.brand setting still held for it. It returns the undo payload: what was there
 * before, keyed by setting, column, language and brand key. Media ids travel as media keys, never
 * as row ids. undo() writes that payload back.
 *
 * The payload is trusted only as far as the section allows: undo() writes back nothing outside the
 * section's own keys and columns, and looks every media key up again through the site's scope (an
 * image since deleted, or of another site, stays empty). The screen keeps the payload on the
 * server side of the component (a locked property), never takes it from a request argument.
 *
 * Both run behind the settings permission, in one transaction, through the models, so every write
 * stamps who made it (RecordsWhoSaved) and the storefront caches that listen to settings saves are
 * cleared.
 *
 * @phpstan-type UndoPayload array{section: string, settings: array<string, array<array-key, mixed>|null>, profile: array<string, mixed>, translations: array<string, array<string, mixed>>, brand: array<string, mixed>}
 */
final class ResetSettingsSection
{
    /**
     * @return UndoPayload
     *
     * @throws SettingsChangeRejected
     */
    public function reset(string $section): array
    {
        Gate::authorize('settings.manage');
        $definition = SettingsSections::get($section);

        return DB::transaction(function () use ($section, $definition): array {
            $undo = ['section' => $section, 'settings' => [], 'profile' => [], 'translations' => [], 'brand' => []];
            // The brand row is written back whole: lock it first, then read it (below), so a save
            // running at the same time waits instead of being undone.
            $brandRow = $definition['brand_keys'] !== []
                ? SiteSetting::query()->where('group_name', 'general')->where('key', 'brand')->lockForUpdate()->first()
                : null;

            foreach ($definition['settings'] as [$group, $key]) {
                $row = SiteSetting::query()->where('group_name', $group)->where('key', $key)->first();
                $undo['settings']["{$group}.{$key}"] = $row?->value;
                $row?->delete();
            }

            $profile = $definition['profile'] !== [] || $definition['translations'] !== []
                ? SiteProfile::query()->first()
                : null;
            if ($profile !== null) {
                foreach ($definition['profile'] as $column => $default) {
                    $undo['profile'][$column] = $this->outbound($column, $profile->getAttribute($column));
                    $profile->setAttribute($column, $default);
                }
                if ($definition['translations'] !== []) {
                    foreach (SiteProfileTranslation::query()->where('site_profile_id', $profile->id)->get() as $row) {
                        foreach ($definition['translations'] as $column) {
                            $undo['translations'][$row->locale][$column] = $row->getAttribute($column);
                            $row->setAttribute($column, null);
                        }
                        $row->save();
                    }
                }
            }

            if ($definition['brand_keys'] !== []) {
                $brand = Cast::arr($brandRow?->value);
                $undo['brand'] = array_intersect_key($brand, array_flip($definition['brand_keys']));
                if ($brandRow !== null && $undo['brand'] !== []) {
                    $brandRow->value = array_diff_key($brand, $undo['brand']);
                    $brandRow->save();
                }
            }

            // Every reset of a profile-backed section writes the profile row, so its stamp follows.
            if ($profile !== null) {
                $profile->isDirty() ? $profile->save() : $profile->touch();
            }

            return $undo;
        });
    }

    /**
     * @param  array<array-key, mixed>  $undo  a payload reset() returned
     *
     * @throws SettingsChangeRejected
     */
    public function undo(array $undo): void
    {
        Gate::authorize('settings.manage');
        $section = is_string($undo['section'] ?? null) ? $undo['section'] : '';
        $definition = SettingsSections::get($section);
        $settings = is_array($undo['settings'] ?? null) ? $undo['settings'] : [];
        $columns = is_array($undo['profile'] ?? null) ? $undo['profile'] : [];
        $texts = is_array($undo['translations'] ?? null) ? $undo['translations'] : [];
        $brandValues = is_array($undo['brand'] ?? null) ? $undo['brand'] : [];

        DB::transaction(function () use ($definition, $settings, $columns, $texts, $brandValues): void {
            // The brand row is written back whole: lock it first, then read it (below).
            $restore = array_intersect_key($brandValues, array_flip($definition['brand_keys']));
            $brandRow = $restore !== []
                ? SiteSetting::query()->lockForUpdate()->firstOrNew(['group_name' => 'general', 'key' => 'brand'])
                : null;

            foreach ($definition['settings'] as [$group, $key]) {
                if (! array_key_exists("{$group}.{$key}", $settings)) {
                    continue;
                }
                $value = $settings["{$group}.{$key}"];
                if (is_array($value)) {
                    SiteSetting::query()->updateOrCreate(['group_name' => $group, 'key' => $key], ['value' => $value]);
                } else {
                    SiteSetting::query()->where('group_name', $group)->where('key', $key)->first()?->delete();
                }
            }

            $profile = null;
            if ($definition['profile'] !== [] || $definition['translations'] !== []) {
                $profile = SiteProfile::query()->firstOrNew();
                foreach (array_keys($definition['profile']) as $column) {
                    if (array_key_exists($column, $columns)) {
                        $profile->setAttribute($column, $this->inbound($column, $columns[$column]));
                    }
                }
                $profile->save();

                foreach ($texts as $locale => $values) {
                    if (! is_array($values)) {
                        continue;
                    }
                    $row = SiteProfileTranslation::query()->where('site_profile_id', $profile->id)->where('locale', (string) $locale)->first();
                    if ($row === null) {
                        continue;
                    }
                    foreach ($definition['translations'] as $column) {
                        if (array_key_exists($column, $values)) {
                            $row->setAttribute($column, is_scalar($values[$column]) ? (string) $values[$column] : null);
                        }
                    }
                    $row->save();
                }
            }

            if ($brandRow !== null) {
                $brandRow->value = [...Cast::arr($brandRow->getAttribute('value')), ...$restore];
                $brandRow->save();
            }
        });
    }

    /** A media id leaves as its media key; every other value as it is. */
    private function outbound(string $column, mixed $value): mixed
    {
        if (! in_array($column, SettingsSections::MEDIA_COLUMNS, true)) {
            return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return is_int($value) ? Media::query()->whereKey($value)->value('storage_key') : null;
    }

    /** A media key comes back as the id of this site's picture with that key (none if it is gone). */
    private function inbound(string $column, mixed $value): mixed
    {
        if (! in_array($column, SettingsSections::MEDIA_COLUMNS, true)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        $id = Media::query()->where('storage_key', $value)->where('mime', 'like', 'image/%')->orderBy('id')->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
