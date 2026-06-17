<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * GLOBAL table (D6: no site_id, no RLS) — it IS the tenant key source.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $name
 * @property string $status
 */
final class Site extends Model
{
    use HasUlidColumn;
    use SoftDeletes;

    protected $guarded = [];

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }
}
