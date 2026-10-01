<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * GLOBAL table (D6). Maps a request hostname -> site. hostname is citext (D12) so
 * matching is case-insensitive.
 *
 * verified_at is the "may be served" marker: a platform subdomain carries it from birth, a
 * customer's own domain once it points at the platform (design.md §28). ssl_status is null until
 * HTTPS is turned on for the domain.
 *
 * @property int $id
 * @property int $site_id
 * @property string $hostname
 * @property string $kind
 * @property bool $is_primary
 * @property string|null $ssl_status
 * @property Carbon|null $verified_at
 * @property Carbon|null $checked_at
 * @property Carbon|null $failing_since
 * @property string|null $failure_reason
 * @property string|null $pointing_method
 */
final class Domain extends Model
{
    #[\Override]
    protected $guarded = [];

    #[\Override]
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'checked_at' => 'datetime',
            'failing_since' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
