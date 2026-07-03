<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GLOBAL catalog (D6: no site_id, no RLS) — platform-writable only (gate:
 * `platform.site_types.manage`). Defines a site's functional shape: its module set +
 * role-template set, both sourced from CODE registries keyed by `key` (design.md §15.2).
 *
 * @property int $id
 * @property string $key
 * @property array<string, string> $label
 * @property array<string, mixed> $meta
 */
final class SiteType extends Model
{
    #[\Override]
    protected $guarded = [];

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'label' => 'array',
            'meta' => 'array',
        ];
    }
}
