<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;

/**
 * A product's ordered gallery (product_images, position = index, 0 = the main picture) and the content
 * mark that follows it: the pictures are part of the product page's main content (its photos, the
 * Product `image`), so the page's content changes when a picture it shows is added, removed or moved.
 *
 * Every writer that changes the gallery of an existing product goes through sync() (the product
 * editor, the content-pack applier, the An Vinh promotion). The one other writer, the measurement-site
 * seeder, gives a product it has just created its first picture with images()->create(): that
 * product's languages are new rows, marked when they were created. A picture whose file is in the
 * media trash is not shown (the Media scope leaves it out), so it does not count: keeping or dropping
 * one changes nothing on the page. The file going into or out of the trash does (mediaChanged(),
 * called by Catalog's listener on Media's events).
 */
final class ProductGallery
{
    /**
     * Make the product's gallery exactly these media, in this order. Writes nothing when it already is.
     *
     * @param  list<int>  $mediaIds
     */
    public function sync(Product $product, array $mediaIds): void
    {
        $mediaIds = array_values(array_unique($mediaIds));
        $before = array_values(array_map(
            static fn (mixed $id): int => Cast::int($id),
            ProductImage::query()->where('product_id', $product->id)->orderBy('position')->orderBy('id')->pluck('media_id')->all(),
        ));
        if ($before === $mediaIds) {
            return;
        }

        ProductImage::query()
            ->where('product_id', $product->id)
            ->when($mediaIds !== [], fn ($rows) => $rows->whereNotIn('media_id', $mediaIds))
            ->delete();
        // updateOrCreate rides the UNIQUE(product_id, media_id) index instead of tripping it;
        // BelongsToSite stamps site_id on the created rows.
        foreach ($mediaIds as $position => $mediaId) {
            ProductImage::query()->updateOrCreate(
                ['product_id' => $product->id, 'media_id' => $mediaId],
                ['position' => $position],
            );
        }

        $live = $this->live([...$before, ...$mediaIds]);
        if (self::shown($before, $live) !== self::shown($mediaIds, $live)) {
            Product::markContentChanged([$product->id]);
        }
    }

    /**
     * A media file went into the trash, came back, or is being deleted while still live: mark every
     * product of its site whose gallery holds it.
     */
    public function mediaChanged(Media $media): void
    {
        $products = ProductImage::query()
            ->where('site_id', $media->site_id)
            ->where('media_id', $media->id)
            ->distinct()
            ->pluck('product_id')
            ->all();

        Product::markContentChanged(array_values(array_map(static fn (mixed $id): int => Cast::int($id), $products)));
    }

    /**
     * Of these media, the ones a page shows (this site's, not in the trash), as a set.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function live(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        return $ids === [] ? [] : array_flip(array_map(
            static fn (mixed $id): int => Cast::int($id),
            Media::query()->whereIn('id', $ids)->pluck('id')->all(),
        ));
    }

    /**
     * Of $ids, in order, those in the $live set.
     *
     * @param  list<int>  $ids
     * @param  array<int, int>  $live
     * @return list<int>
     */
    private static function shown(array $ids, array $live): array
    {
        return array_values(array_filter($ids, static fn (int $id): bool => isset($live[$id])));
    }
}
