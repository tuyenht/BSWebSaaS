<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-locale product content — name/slug/description (rich HTML specs) + seo. site_id → RLS.
 *
 * @property int $product_id
 * @property int $site_id
 * @property string $locale
 * @property string $name
 * @property string $slug
 * @property string|null $description
 */
class ProductTranslation extends Model
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
}
