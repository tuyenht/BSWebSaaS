<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Exceptions\SiteCodeIsPermanentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * GLOBAL table (D6: no site_id, no RLS) — it IS the tenant key source.
 *
 * @property int $id
 * @property string $ulid
 * @property string $code permanent media key prefix, issued once (never changed or reused)
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

    /** The shape of a site code: 8 characters of lowercase Crockford base32 (no i, l, o, u). */
    public const string CODE_PATTERN = '/^[0-9a-hjkmnp-tv-z]{8}$/';

    #[\Override]
    protected static function booted(): void
    {
        self::creating(function (Site $site): void {
            if ($site->getAttribute('code') !== null) {
                throw SiteCodeIsPermanentException::chosen();
            }
            $site->setAttribute('code', self::freshCode());
        });

        self::updating(function (Site $site): void {
            if ($site->isDirty('code')) {
                throw SiteCodeIsPermanentException::changed();
            }
        });
    }

    /**
     * A code no site holds (soft-deleted ones included) and that no storage key existing before site
     * codes starts with: the database draws it (generate_site_code(), also the column default), so the
     * model knows its code without reading the row back.
     */
    public static function freshCode(): string
    {
        $code = Cast::string(DB::scalar('select generate_site_code()'));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new RuntimeException('Could not draw a free website code.');
        }

        return $code;
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
