<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int|null $site_type_id
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

    /**
     * Functional shape: its module set + role templates (design.md §15.2).
     *
     * @return BelongsTo<SiteType, $this>
     */
    public function siteType(): BelongsTo
    {
        return $this->belongsTo(SiteType::class);
    }
}
