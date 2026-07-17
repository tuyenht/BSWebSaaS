<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GLOBAL (non-RLS) named bundle of feature grants a tenant subscribes to via tenants.plan_id.
 * No price: billing is deferred — a plan is an entitlement bundle here, not a pricing row.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property bool $is_active
 */
class Plan extends Model
{
    #[\Override]
    protected $guarded = [];

    /**
     * @return HasMany<PlanFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    #[\Override]
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'meta' => 'array'];
    }
}
