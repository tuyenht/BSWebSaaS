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
