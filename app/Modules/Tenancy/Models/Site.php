<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * GLOBAL table (D6: no site_id, no RLS) — it IS the tenant key source.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $status
 * @property string $default_locale
 */
final class Site extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }
}
