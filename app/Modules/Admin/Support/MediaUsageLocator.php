<?php

namespace App\Modules\Admin\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Cms\Models\Entry;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finds which content uses a media master, and constrains the library grid to used/unused. Media
 * usage is NOT centralised in `mediables` yet (no producer writes it), so this reads the real
 * relations directly: a product's gallery (product_images.media_id) and an entry's cover image
 * (entry.meta['image_url'] = the media's storage_key). Rich-text body images (a URL in the HTML,
 * no media_id) are intentionally out of scope here — they can't be resolved back to a master.
 *
 * Lives in Admin (not Media) on purpose: it reaches into Catalog + Cms, and Catalog already depends
 * on Media — putting this in Media would make the module graph cyclic. All queries are site-scoped
 * (BelongsToSite + RLS), so a resolver call only ever sees the current tenant's content.
 */
final class MediaUsageLocator
{
    /**
     * Every piece of content that uses this media, as {kind, label, url} for the detail panel.
     *
     * @return list<array{kind: string, label: string, url: string}>
     */
    public function usagesFor(Media $media, string $locale): array
    {
        $usages = [];

        $productIds = ProductImage::query()->where('media_id', $media->id)->pluck('product_id')->unique();
        if ($productIds->isNotEmpty()) {
            foreach (Product::query()->with('translations')->whereIn('id', $productIds)->get() as $product) {
                $translation = $product->translationFor($locale);
                $usages[] = [
                    'kind' => 'product',
                    'label' => $translation !== null ? $translation->name : '#'.$product->id,
                    'url' => route('admin.catalog.edit', ['ulid' => $product->ulid]),
                ];
            }
        }

        // Entry usage: the cover (storage_key in meta) OR a block image (media_id materialized into
        // mediables on save). Union so an entry using it in both places appears once.
        $coverEntryIds = Entry::query()->where('meta->image_url', $media->storage_key)->pluck('id');
        $blockEntryIds = Mediable::query()
            ->where('media_id', $media->id)->where('mediable_type', 'entry')->where('collection', 'content')
            ->pluck('mediable_id');

        foreach (Entry::query()->with('translations')->whereIn('id', $coverEntryIds->merge($blockEntryIds)->unique())->get() as $entry) {
            $translation = $entry->translationFor($locale);
            $usages[] = [
                'kind' => $entry->type === 'page' ? 'page' : 'post',
                'label' => $translation !== null ? $translation->title : '#'.$entry->id,
                'url' => route('admin.entries.edit', ['ulid' => $entry->ulid]),
            ];
        }

        return $usages;
    }

    /**
     * Constrain a media query to used (true) / unused (false): used = in a product gallery OR an
     * entry cover. The sub-selects are themselves site-scoped, so this never leaks across tenants.
     *
     * @param  Builder<Media>  $query
     */
    public function constrain(Builder $query, bool $used): void
    {
        $galleryMediaIds = ProductImage::query()->select('media_id');
        $coverKeys = Entry::query()->whereNotNull('meta->image_url')->select(DB::raw("meta->>'image_url'"));
        $blockMediaIds = Mediable::query()->where('collection', 'content')->select('media_id');

        if ($used) {
            $query->where(function (Builder $q) use ($galleryMediaIds, $coverKeys, $blockMediaIds): void {
                $q->whereIn('id', $galleryMediaIds)
                    ->orWhereIn('storage_key', $coverKeys)
                    ->orWhereIn('id', $blockMediaIds);
            });

            return;
        }

        $query->whereNotIn('id', $galleryMediaIds)
            ->whereNotIn('storage_key', $coverKeys)
            ->whereNotIn('id', $blockMediaIds);
    }
}
