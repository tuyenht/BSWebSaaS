<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A province-level administrative unit (tỉnh / thành phố trực thuộc trung ương) — GLOBAL reference
 * data (D6, no site_id, no RLS), loaded from the official list by `admin-units:import`, never
 * edited by tenants.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Province extends Model
{
    public $timestamps = false;

    #[\Override]
    protected $guarded = [];

    /**
     * @return HasMany<Ward, $this>
     */
    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return ['valid_from' => 'immutable_date', 'valid_to' => 'immutable_date'];
    }
}
