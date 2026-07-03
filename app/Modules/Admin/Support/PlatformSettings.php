<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Models\PlatformSetting;

/**
 * Reads/writes platform-global config (platform_settings, NOT tenant-scoped) — the
 * defaults tenants fall back to (e.g. the platform email + shared OAuth apps). Secret
 * fields are AES-256-GCM encrypted via SecretCipher, the same dedicated key used for
 * tenant secrets.
 */
final class PlatformSettings
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $group, string $key = 'config'): ?array
    {
        $row = PlatformSetting::query()
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
        PlatformSetting::query()->updateOrCreate(
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
