<?php

namespace App\Modules\Media\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A soft, polymorphic attachment of a media master to any owner (entry/product/banner…), so one
 * library asset is reused everywhere (design.md §16, D3). Tenant table (site_id + RLS). The link is
 * soft: purging a master NULLs media_id and the orphan is swept — an attachment never blocks
 * deleting a master.
 *
 * @property int|null $media_id
 * @property string $mediable_type
 * @property int $mediable_id
 * @property int $site_id
 * @property string $collection
 * @property int $sort_order
 */
final class Mediable extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Media, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** @return MorphTo<Model, $this> */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }
}
