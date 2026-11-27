<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * One running total of a website (site_counters): a metric (`media.bytes`…) for the whole website
 * (subject_id 0) or for one subject of it. Read-only through this model: every write is an upsert of
 * Tenancy\Services\SiteCounters, in the same transaction as the change it counts.
 *
 * @property int $id
 * @property int $site_id
 * @property string $metric
 * @property int $subject_id
 * @property int $value
 */
final class SiteCounter extends Model
{
    use BelongsToSite;

    public const CREATED_AT = null;

    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'value' => 'integer',
        ];
    }
}
