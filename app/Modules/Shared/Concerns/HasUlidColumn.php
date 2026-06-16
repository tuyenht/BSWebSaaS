<?php

namespace App\Modules\Shared\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Auto-fills the external `ulid` column on create (D2: bigint PK internally,
 * ulid for externally-exposed entities). Raw/admin inserts are unaffected — the
 * hook is Eloquent-only and skips when a ulid is already provided.
 */
trait HasUlidColumn
{
    protected static function bootHasUlidColumn(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('ulid'))) {
                $model->setAttribute('ulid', (string) Str::ulid());
            }
        });
    }
}
