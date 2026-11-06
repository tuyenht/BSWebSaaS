<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Contracts\PageLinks;
use App\Modules\Theme\Chrome\StorefrontChrome;
use App\Modules\Theme\Components\ButtonLink;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;

/**
 * The parts of a product page beyond its name and description: the photo gallery (each photo
 * sized, with a srcset and a small thumbnail), the call and Zalo links of the contact box (from the
 * site's business identity) and the other products of its main category.
 *
 * @phpstan-type Photo array{url: string, srcset: string|null, thumb: string, alt: string, width: int, height: int}
 * @phpstan-type Contact array{call: string|null, number: string, zalo: string|null}
 */
final readonly class ProductDetail
{
    /** How many products of the same group follow the description. */
    public const int RELATED = 4;

    private const array SRCSET_WIDTHS = [640, 960, 1280, 1600];

    private const int MAIN_WIDTH = 1280;

    private const int THUMB_WIDTH = 320;

    public function __construct(
        private MediaUrlResolver $media,
        private ListPage $lists,
        private PageLinks $pages,
    ) {}

    /**
     * The product's photos in gallery order; one without a stored file is skipped.
     *
     * @return list<Photo>
     */
    public function photos(Product $product, string $alt): array
    {
        $photos = [];
        foreach ($product->images as $image) {
            $media = $image->media;
            $key = $media?->storage_key;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $width = $media->width !== null && $media->width > 0 ? $media->width : 1600;
            $height = $media->height !== null && $media->height > 0 ? $media->height : 1200;
            $photos[] = [
                'url' => $this->media->url($key, self::MAIN_WIDTH),
                'srcset' => $this->media->srcset($key, self::SRCSET_WIDTHS, $media->width),
                'thumb' => $this->media->url($key, self::THUMB_WIDTH),
                'alt' => $alt,
                'width' => $width,
                'height' => $height,
            ];
        }

        return $photos;
    }

    /**
     * The address of the current site's contact page in this language, built like every storefront
     * link: the page named by the site's `catalog` / `contact_page` setting (its ulid, read with the
     * storefront's other settings), by its entry, so a renamed
     * slug or a language with a slug of its own still finds it. Null when the setting is empty or
     * the page is not reachable here (not published, its translation in this language not
     * published, or trashed), so the quote button is left out.
     */
    public function quoteLink(string $locale, ?string $ulid): ?string
    {
        if ($ulid === null) {
            return null;
        }

        $path = $this->pages->paths([$ulid], $locale)[$ulid] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * The contact box's links: a call to the hotline (else the main phone) and the Zalo contact
     * from the site's social links. Null when the site has neither.
     *
     * @param  array<array-key, mixed>|null  $brand  SiteIdentity::for()
     * @return Contact|null
     */
    public static function contact(?array $brand): ?array
    {
        $number = '';
        $call = null;
        foreach (['hotline', 'phone'] as $field) {
            $value = $brand[$field] ?? null;
            if (is_string($value) && trim($value) !== '' && ($href = ButtonLink::tel($value)) !== null) {
                [$number, $call] = [trim($value), $href];
                break;
            }
        }
        $links = $brand['social_links'] ?? null;
        $zalo = StorefrontChrome::zalo(is_array($links) ? ($links['zalo'] ?? null) : null);

        return $call === null && $zalo === null ? null : ['call' => $call, 'number' => $number, 'zalo' => $zalo];
    }

    /**
     * Up to RELATED other published products whose main category is this one, newest first — only
     * the columns a card reads, their photo and translation loaded in one read each.
     *
     * @return list<array<string, mixed>>
     */
    public function related(Product $product, Taxonomy $category, string $locale): array
    {
        $rows = Product::query()
            ->select(['products.id', 'products.site_id', 'products.published_at'])
            ->join('product_taxonomies', fn (JoinClause $join) => $join->on('product_taxonomies.product_id', '=', 'products.id')
                ->where('product_taxonomies.position', 0)
                ->where('product_taxonomies.taxonomy_id', $category->id))
            ->where('products.status', 'published')
            ->where('products.id', '!=', $product->id)
            ->whereHas('translations', fn ($query) => $query->where('locale', $locale))
            ->orderByRaw('products.published_at DESC NULLS FIRST, products.id DESC')
            ->limit(self::RELATED)
            ->with([
                'translations' => fn (Relation $query) => $query->where('locale', $locale)
                    ->select(['id', 'product_id', 'locale', 'name', 'slug', 'summary']),
                'images' => fn (Relation $query) => $query->select(['id', 'product_id', 'media_id', 'position']),
                'images.media' => fn (Relation $query) => $query->select(['id', 'storage_key', 'width']),
            ])
            ->get();

        // Every row shares this main category: hand it over instead of reading it again.
        foreach ($rows as $row) {
            $row->setRelation('taxonomies', new Collection([$category]));
        }

        return $this->lists->productCards($rows, $locale);
    }
}
