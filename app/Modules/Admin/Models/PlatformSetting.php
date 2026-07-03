<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform-global grouped key/value config (NOT tenant-scoped) — the fallback defaults
 * tenants inherit, e.g. the platform email/SMTP and shared OAuth app credentials. Secret
 * fields within `value` are AES-256-GCM encrypted via SecretCipher before persistence.
 *
 * @property string $group_name
 * @property string $key
 * @property array<string, mixed>|null $value
 */
class PlatformSetting extends Model
{
    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
