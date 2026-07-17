<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single feature grant on a plan (GLOBAL, non-RLS). Composite-keyed (plan_id, feature_key), so
 * it carries no auto-increment id and never updates its timestamps.
 *
 * @property int $plan_id
 * @property string $feature_key
 * @property mixed $value boolean grant or a numeric limit (jsonb)
 */
class PlanFeature extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    public $timestamps = false;

    #[\Override]
    protected $guarded = [];

    #[\Override]
    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
