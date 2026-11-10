<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\ProductSearchWords;
use App\Modules\Cms\Concerns\HasCategoryTaxonomies;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $published_at
 * @property array<string, mixed> $meta
 */
class Product extends Model
{
    use BelongsToSite;
    use HasCategoryTaxonomies;
    use HasUlidColumn;
    use SoftDeletes;

    #[\Override]
    protected $guarded = [];

    /** A new SKU, or a changed one, rewrites the product's search words in the same transaction (ProductSearchWords). */
    #[\Override]
    protected static function booted(): void
    {
        static::created(static function (self $product): void {
            if ($product->sku !== null) {
                ProductSearchWords::sync($product->site_id, $product->id);
            }
        });
        static::updated(static function (self $product): void {
            if ($product->wasChanged('sku')) {
                ProductSearchWords::sync($product->site_id, $product->id);
            }
        });
    }

    /**
     * Mark that the main content of these products changed, in every language (TracksContentChange),
     * in one statement in the caller's transaction. The site scope and RLS keep it to this site.
     *
     * @param  list<int>  $ids
     */
    public static function markContentChanged(array $ids): void
    {
        if ($ids !== []) {
            ProductTranslation::query()->whereIn('product_id', $ids)->update(['content_changed_at' => now()]);
        }
    }

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
            'published_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
