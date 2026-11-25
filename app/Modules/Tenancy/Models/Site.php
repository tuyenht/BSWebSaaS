<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Exceptions\SiteCodeIsPermanentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * GLOBAL table (D6: no site_id, no RLS) — it IS the tenant key source.
 *
 * @property int $id
 * @property string $ulid
 * @property string $code the permanent code, first folder of the site's media keys (issued by the database on insert)
 * @property int $tenant_id
 * @property string $name
 * @property string $status
 * @property int|null $site_type_id
 */
final class Site extends Model
{
    use HasUlidColumn;
    use SoftDeletes;

    #[\Override]
    protected $guarded = [];

    /**
     * The code is issued once, by the database, and never changed (a trigger refuses it as well);
     * refusing it here names the mistake before a query is sent.
     */
    #[\Override]
    protected static function booted(): void
    {
        self::updating(static function (self $site): void {
            $original = $site->getOriginal('code');
            if ($site->isDirty('code') && is_string($original)) {
                throw new SiteCodeIsPermanentException($original);
            }
        });
    }

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

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
