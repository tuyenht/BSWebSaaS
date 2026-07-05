<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product gallery image → media (§11), ordered by position. site_id → RLS (D6).
 *
 * @property int $product_id
 * @property int $site_id
 * @property int $media_id
 * @property int $position
 */
class ProductImage extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
