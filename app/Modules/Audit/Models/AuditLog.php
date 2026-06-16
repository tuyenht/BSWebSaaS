<?php

namespace App\Modules\Audit\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit entry. Global/platform (no RLS); nullable site_id for context.
 *
 * @property int $id
 * @property string $ulid
 * @property string $action
 * @property int|null $site_id
 * @property array<string, mixed> $meta
 */
class AuditLog extends Model
{
    use HasUlidColumn;

    public const UPDATED_AT = null; // append-only: created_at only

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
