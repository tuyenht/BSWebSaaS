<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\ProductSearchWords;
use App\Modules\Catalog\Support\ProductSiteWords;
use App\Modules\Cms\Concerns\TracksContentChange;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-locale product content — name/slug + sanitized rich HTML (description = detail/specs,
 * summary = list-card teaser) + SEO meta. site_id → RLS.
 *
 * @property int $product_id
 * @property int $site_id
 * @property string $locale
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $summary
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property Carbon|null $content_changed_at when the page's main content last changed (TracksContentChange)
 */
class ProductTranslation extends Model
{
    use BelongsToSite;
    use TracksContentChange;

    #[\Override]
    protected $guarded = [];

    /**
     * A new name, a changed one or a language removed rewrites the product's admin search words
     * (ProductSearchWords) and this language's storefront search words (ProductSiteWords) in the same
     * transaction; a changed summary rewrites the storefront ones; a row moved to another language or
     * product rewrites both where it was and where it is. A save that leaves these as they were writes
     * nothing.
     */
    #[\Override]
    protected static function booted(): void
    {
        $sync = static function (self $row): void {
            ProductSearchWords::sync($row->site_id, $row->product_id);
            ProductSiteWords::sync($row->site_id, $row->product_id, $row->locale);
        };
        static::created($sync);
        static::updated(static function (self $row) use ($sync): void {
            if ($row->wasChanged(['locale', 'product_id'])) {
                $from = Cast::int($row->getOriginal('product_id'));
                if ($from !== $row->product_id) {
                    ProductSearchWords::sync($row->site_id, $from);
                }
                ProductSiteWords::sync($row->site_id, $from, Cast::string($row->getOriginal('locale')));
                $sync($row);
            } elseif ($row->wasChanged('name')) {
                $sync($row);
            } elseif ($row->wasChanged('summary')) {
                ProductSiteWords::sync($row->site_id, $row->product_id, $row->locale);
            }
        });
        static::deleted($sync);
    }

    /** The columns the product page shows as its main content: name, summary, description, Google's title and description. */
    public function contentFields(): array
    {
        return ['name', 'summary', 'description', 'seo_title', 'seo_description'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
