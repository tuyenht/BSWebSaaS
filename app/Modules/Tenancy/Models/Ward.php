<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ward-level administrative unit (xã / phường / đặc khu) — GLOBAL reference data (D6, no
 * site_id, no RLS), loaded from the official list by `admin-units:import`, never edited by tenants.
 *
 * @property int $id
 * @property int $province_id
 * @property string $code
 * @property string $name
 */
class Ward extends Model
{
    public $timestamps = false;

    #[\Override]
    protected $guarded = [];

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
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
