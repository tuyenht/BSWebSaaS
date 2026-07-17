<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * A per-site override of one feature (tenant table: site_id + RLS). Its presence overrides
 * whatever the tenant's plan grants — set a lower value to cap, or false to deny outright.
 *
 * @property int $site_id
 * @property string $feature_key
 * @property mixed $value boolean grant or a numeric limit (jsonb)
 */
class SiteFeature extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    #[\Override]
    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
