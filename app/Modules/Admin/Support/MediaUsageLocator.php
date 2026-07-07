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
     * Every place this media is used, tagged by role for the grouped detail panel: `gallery` (a
     * product image list), `cover` (an entry's featured/OG image), `content` (a block image inside an
     * entry body). An entry that uses the media in two roles appears once per role.
     *
     * @return list<array{kind: string, role: string, label: string, url: string}>
     */
    public function usagesFor(Media $media, string $locale): array
    {
        $usages = [];

        // Gallery — a product's image list (product_images).
        $productIds = ProductImage::query()->where('media_id', $media->id)->pluck('product_id')->unique();
        foreach (Product::query()->with('translations')->whereIn('id', $productIds)->get() as $product) {
            $translation = $product->translationFor($locale);
            $usages[] = [
                'kind' => 'product',
                'role' => 'gallery',
                'label' => $translation !== null ? $translation->name : '#'.$product->id,
                'url' => route('admin.catalog.edit', ['ulid' => $product->ulid]),
            ];
        }

        // Cover — the entry's featured/OG image (storage_key in meta).
        foreach (Entry::query()->with('translations')->where('meta->image_url', $media->storage_key)->get() as $entry) {
            $usages[] = $this->entryUsage($entry, 'cover', $locale);
        }

        // Content — a block image (media_id materialized into mediables on save).
        $blockEntryIds = Mediable::query()
            ->where('media_id', $media->id)->where('mediable_type', 'entry')->where('collection', 'content')
            ->pluck('mediable_id')->unique();
        foreach (Entry::query()->with('translations')->whereIn('id', $blockEntryIds)->get() as $entry) {
            $usages[] = $this->entryUsage($entry, 'content', $locale);
        }

        return $usages;
    }

    /**
     * @return array{kind: string, role: string, label: string, url: string}
     */
    private function entryUsage(Entry $entry, string $role, string $locale): array
    {
        $translation = $entry->translationFor($locale);

        return [
            'kind' => $entry->type === 'page' ? 'page' : 'post',
            'role' => $role,
            'label' => $translation !== null ? $translation->title : '#'.$entry->id,
            'url' => route('admin.entries.edit', ['ulid' => $entry->ulid]),
        ];
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
