<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Tenancy\Models\SiteSetting;

/**
 * Reads/writes the current tenant's grouped key/value config (site_settings, auto-scoped
 * by BelongsToSite). Secret fields (passwords, client secrets) are stored AES-256-GCM
 * encrypted via SecretCipher — never persisted in plaintext.
 */
final class SettingsRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $group, string $key = 'config'): ?array
    {
        $row = SiteSetting::query()
            ->where('group_name', $group)
            ->where('key', $key)
            ->first();

        return is_array($row?->value) ? $row->value : null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function put(string $group, string $key, array $value): void
    {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => $group, 'key' => $key],
            ['value' => $value],
        );
    }

    /**
     * Rewrites one of the current website's rows while holding its lock, so nothing else writes it
     * between the read and the write: $change gets the current value (null when there is no row)
     * and returns the new value, or null to leave the row untouched. The row is read through the
     * website's scope (BelongsToSite), and a new one is created for the current website. Inside the
     * request's tenant transaction this is a savepoint: the lock lasts until that transaction ends.
     *
     * @param  callable(array<string, mixed>|null): (array<string, mixed>|null)  $change
     * @return array<string, mixed>|null the value written, or null when nothing was written
     */
    public function change(string $group, string $key, callable $change): ?array
    {
        return (new SiteSetting)->getConnection()->transaction(function () use ($group, $key, $change): ?array {
            $row = SiteSetting::query()
                ->where('group_name', $group)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            $value = $change(is_array($row?->value) ? $row->value : null);
            if ($value === null) {
                return null;
            }

            if ($row === null) {
                SiteSetting::query()->create(['group_name' => $group, 'key' => $key, 'value' => $value]);
            } else {
                $row->value = $value;
                $row->save();
            }

            return $value;
        });
    }

    public function encryptSecret(string $plain): string
    {
        return SecretCipher::encrypt($plain);
    }

    public function decryptSecret(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        return SecretCipher::decrypt($payload);
    }
}
