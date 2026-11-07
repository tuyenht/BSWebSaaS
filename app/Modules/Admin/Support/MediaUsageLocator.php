<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Support\Categories\CategorySections;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Models\SiteProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finds which content uses a media master, and constrains the library grid to used/unused. Media
 * usage is NOT centralised in `mediables` yet (no producer writes it), so this reads the real
 * relations directly: a product's gallery (product_images.media_id), an entry's cover image
 * (entry.meta['image_url'] = the media's storage_key), a category's picture
 * (taxonomies.cover_media_id) and the site's four brand images (site_profiles: logo, logo on a dark
 * background, favicon, share image; role `brand`, kind `site`). Rich-text body images (a URL in the HTML, no media_id) are
 * intentionally out of scope here — they can't be resolved back to a master.
 *
 * Lives in Admin (not Media) on purpose: it reaches into Catalog + Cms, and Catalog already depends
 * on Media — putting this in Media would make the module graph cyclic. All queries are site-scoped
 * (BelongsToSite + RLS), so a resolver call only ever sees the current tenant's content.
 */
final readonly class MediaUsageLocator
{
    public function __construct(private CategorySections $sections) {}

    /**
     * Every place this media is used, tagged by role for the grouped detail panel: `gallery` (a
     * product image list), `cover` (an entry's featured/OG image, or a category's picture), `content`
     * (a block image, or a block button's file to download, inside an entry body, the site's block
     * slots or a category's own slot blocks). An entry that uses the media in two roles appears once
     * per role.
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
                'label' => $translation !== null ? $translation->name : __('catalog.untitled'),
                'url' => route('admin.catalog.edit', ['ulid' => $product->ulid]),
            ];
        }

        // Cover — the entry's featured/OG image (storage_key in meta).
        foreach (Entry::query()->with('translations')->where('meta->image_url', $media->storage_key)->get() as $entry) {
            $usages[] = $this->entryUsage($entry, 'cover', $locale);
        }

        // Cover — a category's picture (one for every language).
        foreach (Taxonomy::query()->with('translations')->where('cover_media_id', $media->id)->orderBy('id')->get() as $category) {
            $translation = $category->translationFor($locale);
            $usages[] = [
                'kind' => 'category',
                'role' => 'cover',
                'label' => $translation !== null ? $translation->name : __('catalog.untitled'),
                'url' => route('admin.taxonomies.index'),
            ];
        }

        // Brand — the site's logo, logo on a dark background, favicon or share image (one entry per slot).
        $profile = SiteProfile::query()->first(array_values(SiteProfile::IMAGE_COLUMNS));
        foreach (SiteProfile::IMAGE_COLUMNS as $column) {
            if ($profile !== null && $profile->getAttribute($column) === $media->id) {
                $usages[] = [
                    'kind' => 'site',
                    'role' => 'brand',
                    'label' => __('admin.appearance'),
                    'url' => route('admin.settings.appearance'),
                ];
            }
        }

        // Content — a block image or a button's file (materialized into mediables on save): in an
        // entry body, in the site's block slots (around posts, lists and products) or in a
        // category's own blocks for those slots.
        $owners = ['entry' => [], 'site_setting' => [], 'taxonomy' => []];
        $rows = Mediable::query()
            ->where('media_id', $media->id)->where('collection', 'content')->whereIn('mediable_type', array_keys($owners))
            ->get(['mediable_type', 'mediable_id']);
        foreach ($rows as $row) {
            $owners[$row->mediable_type][] = $row->mediable_id;
        }
        foreach (Entry::query()->with('translations')->whereIn('id', array_unique($owners['entry']))->get() as $entry) {
            $usages[] = $this->entryUsage($entry, 'content', $locale);
        }
        if ($owners['site_setting'] !== []) {
            $usages[] = [
                'kind' => 'site',
                'role' => 'content',
                'label' => __('media.usage_layout_slots'),
                'url' => route('admin.settings.appearance'),
            ];
        }
        if ($owners['taxonomy'] !== []) {
            $categories = Taxonomy::query()->with('translations')->whereIn('id', array_unique($owners['taxonomy']))->orderBy('id')->get();
            foreach ($categories as $category) {
                $translation = $category->translationFor($locale);
                $section = $this->sectionOf($category);
                $usages[] = [
                    'kind' => 'category',
                    'role' => 'content',
                    'label' => __('media.usage_layout_slots_of', ['name' => $translation !== null ? $translation->name : __('catalog.untitled')]),
                    'url' => route('admin.taxonomies.index', $section === null ? [] : ['section' => $section]),
                ];
            }
        }

        return $usages;
    }

    /**
     * The key of the Chuyên mục screen section a category is edited in (CategorySections): the
     * catalog for a product category, else the post section built on the category's root. Null when
     * no section shows it (the screen then opens on its default).
     */
    private function sectionOf(Taxonomy $category): ?string
    {
        if ($category->vocabulary === CategorySections::PRODUCT_VOCABULARY) {
            return CategorySections::CATALOG;
        }

        $root = TaxonomyTree::ancestorsOf($category->id)[0] ?? $category->id;
        foreach ($this->sections->postSections() as $section) {
            if ($section['root'] === $root) {
                return $section['key'];
            }
        }

        return null;
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
            'label' => $translation !== null ? $translation->title : __('entries.untitled'),
            'url' => route('admin.entries.edit', ['ulid' => $entry->ulid]),
        ];
    }

    /**
     * Constrain a media query to used (true) / unused (false): used = in a product gallery, an entry
     * cover, an entry body, a category's picture or one of the site's brand images. The sub-selects are themselves site-scoped, so
     * this never leaks across tenants.
     *
     * @param  Builder<Media>  $query
     */
    public function constrain(Builder $query, bool $used): void
    {
        $galleryMediaIds = ProductImage::query()->select('media_id');
        $coverKeys = Entry::query()->whereNotNull('meta->image_url')->select(DB::raw("meta->>'image_url'"));
        $blockMediaIds = Mediable::query()->where('collection', 'content')->select('media_id');
        // NOT IN with a NULL in the list matches nothing, so categories without a picture stay out.
        $categoryMediaIds = Taxonomy::query()->whereNotNull('cover_media_id')->select('cover_media_id');
        // The four brand image columns of the site's profile, as one list of ids (no NULLs).
        $brandColumns = array_values(SiteProfile::IMAGE_COLUMNS);
        $brandMediaIds = SiteProfile::query()->whereNotNull($brandColumns[0])->select($brandColumns[0]);
        foreach (array_slice($brandColumns, 1) as $column) {
            $brandMediaIds->unionAll(SiteProfile::query()->whereNotNull($column)->select($column));
        }

        if ($used) {
            $query->where(function (Builder $q) use ($galleryMediaIds, $coverKeys, $blockMediaIds, $categoryMediaIds, $brandMediaIds): void {
                $q->whereIn('id', $galleryMediaIds)
                    ->orWhereIn('storage_key', $coverKeys)
                    ->orWhereIn('id', $blockMediaIds)
                    ->orWhereIn('id', $categoryMediaIds)
                    ->orWhereIn('id', $brandMediaIds);
            });

            return;
        }

        $query->whereNotIn('id', $galleryMediaIds)
            ->whereNotIn('storage_key', $coverKeys)
            ->whereNotIn('id', $blockMediaIds)
            ->whereNotIn('id', $categoryMediaIds)
            ->whereNotIn('id', $brandMediaIds);
    }
}
