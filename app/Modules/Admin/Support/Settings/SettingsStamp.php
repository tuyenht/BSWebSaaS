<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A settings section's "Cập nhật lần cuối HH:MM · <người>" line: the newest `updated_at` among the
 * section's stored rows (its site_settings keys, and the site profile for sections stored there),
 * with the display name of the account that made that save (`updated_by`). Null when nothing of the
 * section was ever saved; `by` is null when that save had no one signed in (a command) or the
 * account is gone.
 *
 * of() takes the rows the page already loaded and adds one users lookup; forSection() loads them
 * itself (one settings query, one profile query when the section has profile fields).
 */
final class SettingsStamp
{
    /**
     * @param  iterable<Model|null>  $rows  SiteSetting / SiteProfile rows (null entries are skipped)
     * @return array{at: CarbonImmutable, by: string|null}|null
     */
    public function of(iterable $rows): ?array
    {
        $at = null;
        $by = null;
        foreach ($rows as $row) {
            $time = $row?->getAttribute('updated_at');
            if (! $time instanceof DateTimeInterface) {
                continue;
            }
            $time = CarbonImmutable::instance($time);
            if ($at === null || $time->greaterThan($at)) {
                $at = $time;
                $by = $row->getAttribute('updated_by');
            }
        }

        if ($at === null) {
            return null;
        }

        $name = is_numeric($by) ? User::query()->whereKey((int) $by)->value('name') : null;

        return ['at' => $at, 'by' => is_string($name) && $name !== '' ? $name : null];
    }

    /**
     * @return array{at: CarbonImmutable, by: string|null}|null
     *
     * @throws SettingsChangeRejected
     */
    public function forSection(string $section): ?array
    {
        $definition = SettingsSections::get($section);
        $rows = [];

        if ($definition['settings'] !== []) {
            $query = SiteSetting::query()->select(['id', 'group_name', 'key', 'updated_at', 'updated_by']);
            $query->where(function ($where) use ($definition): void {
                foreach ($definition['settings'] as [$group, $key]) {
                    $where->orWhere(fn ($pair) => $pair->where('group_name', $group)->where('key', $key));
                }
            });
            $rows = $query->get()->all();
        }
        if ($definition['profile'] !== [] || $definition['translations'] !== []) {
            $rows[] = SiteProfile::query()->first(['id', 'updated_at', 'updated_by']);
        }

        return $this->of($rows);
    }
}
