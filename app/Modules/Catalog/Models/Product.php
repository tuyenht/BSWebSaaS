<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catalog product (design.md §11) — a real commerce-typed row (NOT an entry). Per-locale
 * name/slug/description live in product_translations; the ordered gallery in product_images.
 * Brochure mode leaves price/sku/stock null. RLS isolates by site; deleted_at (Trash) is an
 * app-layer scope so Trash can withTrashed.
 *
 * @property int $id
 * @property string $ulid
 * @property int $site_id
 * @property string|null $sku
 * @property int|null $price
 * @property int|null $stock
 * @property bool $is_featured
 * @property string $status
 * @property array<string, mixed> $meta
 */
class Product extends Model
{
    use BelongsToSite;
    use HasUlidColumn;
    use SoftDeletes;

    #[\Override]
    protected $guarded = [];

    /**
     * @return HasMany<ProductTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }

    /**
     * The ordered gallery (position ascending).
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Taxonomy, $this>
     */
    public function taxonomies(): BelongsToMany
    {
        return $this->belongsToMany(Taxonomy::class, 'product_taxonomies')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function translationFor(string $locale): ?ProductTranslation
    {
        // Reuse the eager-loaded relation when present (avoids an N+1); else query the locale.
        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', $locale);
        }

        return $this->translations()->where('locale', $locale)->first();
    }

    /**
     * Sync the product's categories (product_taxonomies). Stamps site_id on each pivot row
     * (the pivot is RLS-protected; sync() does not auto-fill it) and uses array order as the
     * pivot position — index 0 is the primary category used for routing.
     *
     * @param  list<int>  $taxonomyIds
     */
    public function assignCategories(array $taxonomyIds): void
    {
        $payload = [];
        foreach ($taxonomyIds as $position => $id) {
            $payload[$id] = ['position' => $position, 'site_id' => $this->site_id];
        }

        $this->taxonomies()->sync($payload);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'sale_price' => 'integer',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
            'stock' => 'integer',
            'is_featured' => 'boolean',
            'is_bestseller' => 'boolean',
            'meta' => 'array',
        ];
    }
}
