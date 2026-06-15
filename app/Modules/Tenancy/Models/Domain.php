<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GLOBAL table (D6). Maps a request hostname -> site. hostname is citext (D12) so
 * matching is case-insensitive.
 *
 * @property int $id
 * @property int $site_id
 * @property string $hostname
 * @property string $kind
 * @property bool $is_primary
 */
final class Domain extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
