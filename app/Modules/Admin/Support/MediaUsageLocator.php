<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Support\Categories\CategorySections;
use App\Modules\Admin\Support\Settings\BranchList;
use App\Modules\Admin\Support\Settings\FloatingContactInput;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Cms\Support\ContentBlockMediaReconciler;
use App\Modules\Cms\Support\EntryCoverUsage;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Models\SiteProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Finds which content uses a media master, and constrains the library grid to used/unused. Media
 * usage is only partly centralised in `mediables` (the branch list is its one producer so far), so
 * this reads the real relations directly: a product's gallery (product_images.media_id), an entry's cover image
 * (named by storage key in entry.meta['image_url'], read through its "in use" row: mediables
 * collection `cover`, EntryCoverUsage, written with every save of the entry), a category's picture
 * (taxonomies.cover_media_id) and the site's four brand images (site_profiles: logo, logo on a dark
 * background, favicon, share image; role `brand`, kind `site`) and the site's branch and factory
 * pictures (mediables collection `branch`, written with the branch list; role `content`, kind
 * `site`, labelled "Chi nhánh / Nhà máy") and a floating-contact channel's own icon picture
 * (mediables collection `contact_icon`, written on that screen's save; role `content`, kind `site`,
 * labelled with the screen's title "Nút liên hệ nổi"). Rich-text body images (a URL in the HTML, no media_id) are
 * intentionally out of scope here — they can't be resolved back to a master.
 *
 * Lives in Admin (not Media) on purpose: it reaches into Catalog + Cms, and Catalog already depends
 * on Media — putting this in Media would make the module graph cyclic. All queries are site-scoped
 * (BelongsToSite + RLS), so a resolver call only ever sees the current tenant's content.
 */
final readonly class MediaUsageLocator
{
    /**
     * The collections of the library's "in use" rows (mediables) that mean "used": a block's
     * picture or file (content), a branch or factory picture (branch) and a floating-contact
     * channel's own icon picture (contact_icon). Every reader here counts all three, so a picture
     * named only by the branch list or the floating contact is never listed as unused.
     */
    private const array COLLECTIONS = [BlockMediaUsage::COLLECTION, BranchList::COLLECTION, FloatingContactInput::ICON_COLLECTION];

    public function __construct(private CategorySections $sections) {}

    /**
     * Every place this media is used, tagged by role for the grouped detail panel: `gallery` (a
     * product image list), `cover` (an entry's featured/OG image, or a category's picture), `content`
     * (a block image, or a block button's file to download, inside an entry body, the site's block
     * slots, a category's own slot blocks or a shared block's body). An entry that uses the media in two roles appears once
     * per role.
     * `url` is the screen the thing is changed on; null when no admin screen changes it yet (the
     * site's own block slots and its branch list are written by the content-pack loader only).
     * `sub` (products and entries only) is the short line the library's detail drawer prints under
     * the label: a product's code, an entry's address in that locale.
     *
     * @return list<array{kind: string, role: string, label: string, url: string|null, sub?: string|null}>
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
                'sub' => $product->sku !== null && $product->sku !== '' ? $product->sku : null,
            ];
        }

        // Cover — the entry's featured/OG image (its "in use" row, kept with the entry's meta).
        $covered = Mediable::query()->where('media_id', $media->id)->where('mediable_type', 'entry')
            ->where('collection', EntryCoverUsage::COLLECTION)->select('mediable_id');
        foreach (Entry::query()->with('translations')->whereIn('id', $covered)->get() as $entry) {
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

        // Brand — the site's logo, logo on a dark background, favicon or share image (one entry per
        // slot), each to the page it is changed on: the share image on "Google & mạng xã hội", the
        // others on "Giao diện website".
        $profile = SiteProfile::query()->first(array_values(SiteProfile::IMAGE_COLUMNS));
        foreach (SiteProfile::IMAGE_COLUMNS as $slot => $column) {
            if ($profile !== null && $profile->getAttribute($column) === $media->id) {
                $usages[] = [
                    'kind' => 'site',
                    'role' => 'brand',
                    'label' => $slot === 'og_image' ? __('admin.seoset.title') : __('admin.website_look'),
                    'url' => route($slot === 'og_image' ? 'admin.settings.seo' : 'admin.settings.appearance'),
                ];
            }
        }

        // Content — a block image or a button's file (materialized into mediables on save): in an
        // entry body, in the site's block slots (around posts, lists and products) or in a
        // category's own blocks for those slots, or in a shared block's body.
        $owners = ['entry' => [], 'site_setting' => [], 'taxonomy' => [], ContentBlockMediaReconciler::OWNER => []];
        $branch = false;
        $floating = false;
        $rows = Mediable::query()
            ->where('media_id', $media->id)->whereIn('collection', self::COLLECTIONS)->whereIn('mediable_type', array_keys($owners))
            ->get(['mediable_type', 'mediable_id', 'collection']);
        foreach ($rows as $row) {
            if ($row->collection === BranchList::COLLECTION) {
                // A branch or factory picture, named by the site's branch list (storefront.footer).
                $branch = $branch || $row->mediable_type === 'site_setting';

                continue;
            }
            if ($row->collection === FloatingContactInput::ICON_COLLECTION) {
                // A channel's own icon picture, named by the floating contact (storefront.floating_contact).
                $floating = $floating || $row->mediable_type === 'site_setting';

                continue;
            }
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
                'url' => null,
            ];
        }
        if ($branch) {
            $usages[] = [
                'kind' => 'site',
                'role' => 'content',
                'label' => __('media.usage_branches'),
                'url' => null,
            ];
        }
        if ($floating) {
            $usages[] = [
                'kind' => 'site',
                'role' => 'content',
                'label' => __('admin.fc.title'),
                'url' => route('admin.settings.floating-contact'),
            ];
        }
        if ($owners['taxonomy'] !== []) {
            $categories = Taxonomy::query()->with('translations')->whereIn('id', array_unique($owners['taxonomy']))->orderBy('id')->get();
            $roots = $this->rootsOf($categories->all());
            foreach ($categories as $category) {
                $translation = $category->translationFor($locale);
                $section = $this->sectionOf($category, $roots);
                $usages[] = [
                    'kind' => 'category',
                    'role' => 'content',
                    'label' => __('media.usage_layout_slots_of', ['name' => $translation !== null ? $translation->name : __('catalog.untitled')]),
                    'url' => route('admin.taxonomies.index', $section === null ? [] : ['section' => $section]),
                ];
            }
        }

        if ($owners[ContentBlockMediaReconciler::OWNER] !== []) {
            $blocks = ContentBlock::query()->whereIn('id', array_unique($owners[ContentBlockMediaReconciler::OWNER]))
                ->orderBy('name')->orderBy('id')->get(['id', 'ulid', 'name']);
            foreach ($blocks as $block) {
                $usages[] = [
                    'kind' => 'block',
                    'role' => 'content',
                    'label' => __('media.usage_block', ['name' => $block->name]),
                    'url' => route('admin.blocks.edit', ['ulid' => $block->ulid]),
                ];
            }
        }

        return $usages;
    }

    /**
     * How many places use each of a page of files (media id => count), counted as usagesFor() lists
     * them, in one statement for the whole page: each kind of use is one grouped part of a UNION ALL
     * keyed by the page's media ids, its owners read through their own models (site scope, Trash
     * scope), so a soft-deleted product or post no longer counts — as constrain() reads them.
     * An entry's featured / share image is counted from its "in use" row (EntryCoverUsage), never
     * from entries.meta, which no index serves.
     *
     * @param  iterable<Media>  $files
     * @return array<int, int>
     */
    public function countsFor(iterable $files): array
    {
        $ids = [];
        foreach ($files as $file) {
            $ids[] = (int) $file->id;
        }
        if ($ids === []) {
            return [];
        }

        $content = BlockMediaUsage::COLLECTION;
        $parts = [
            // a product's image list: one place per product
            ProductImage::query()->toBase()->whereIn('media_id', $ids)->whereIn('product_id', Product::query()->select('id'))
                ->groupBy('media_id')->selectRaw('media_id as k, count(distinct product_id) as n'),
            // a category's picture
            Taxonomy::query()->toBase()->whereIn('cover_media_id', $ids)
                ->groupBy('cover_media_id')->selectRaw('cover_media_id as k, count(*) as n'),
            // an entry's featured / share image: one place per entry
            Mediable::query()->toBase()->whereIn('media_id', $ids)->where('collection', EntryCoverUsage::COLLECTION)->where('mediable_type', 'entry')
                ->whereIn('mediable_id', Entry::query()->select('id'))
                ->groupBy('media_id')->selectRaw('media_id as k, count(distinct mediable_id) as n'),
            // a block image or a button's file: one place per entry, per category, per shared block, and one for the site's own slots
            Mediable::query()->toBase()->whereIn('media_id', $ids)->where('collection', $content)->where('mediable_type', 'entry')
                ->whereIn('mediable_id', Entry::query()->select('id'))
                ->groupBy('media_id')->selectRaw('media_id as k, count(distinct mediable_id) as n'),
            Mediable::query()->toBase()->whereIn('media_id', $ids)->where('collection', $content)->where('mediable_type', 'taxonomy')
                ->whereIn('mediable_id', Taxonomy::query()->select('id'))
                ->groupBy('media_id')->selectRaw('media_id as k, count(distinct mediable_id) as n'),
            Mediable::query()->toBase()->whereIn('media_id', $ids)->where('collection', $content)->where('mediable_type', ContentBlockMediaReconciler::OWNER)
                ->whereIn('mediable_id', ContentBlock::query()->select('id'))
                ->groupBy('media_id')->selectRaw('media_id as k, count(distinct mediable_id) as n'),
            // the site's own block slots, its branch list and the floating contact: one place each
            Mediable::query()->toBase()->whereIn('media_id', $ids)->whereIn('collection', self::COLLECTIONS)->where('mediable_type', 'site_setting')
                ->groupBy('media_id')->selectRaw('media_id as k, count(distinct collection) as n'),
        ];
        // the site's four brand images: one place per slot
        foreach (array_values(SiteProfile::IMAGE_COLUMNS) as $column) {
            $parts[] = SiteProfile::query()->toBase()->whereIn($column, $ids)
                ->groupBy($column)->selectRaw("{$column} as k, count(*) as n");
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $out = array_fill_keys($ids, 0);
        foreach ($union->get() as $row) {
            $id = is_numeric($row->k) ? (int) $row->k : null;
            if ($id !== null && isset($out[$id])) {
                $out[$id] += is_numeric($row->n) ? (int) $row->n : 0;
            }
        }

        return $out;
    }

    /**
     * The root of each post category without a query per level: the first label of the stored
     * ltree path (ids from the root down), else the parent chain for a row that carries no path.
     *
     * @param  array<int, Taxonomy>  $categories
     * @return array<int, int> category id → root id
     */
    private function rootsOf(array $categories): array
    {
        $roots = [];
        foreach ($categories as $category) {
            if ($category->vocabulary === CategorySections::PRODUCT_VOCABULARY) {
                continue;
            }
            $first = $category->path !== null && $category->path !== '' ? explode('.', $category->path)[0] : '';
            $roots[$category->id] = ctype_digit($first) ? (int) $first : (TaxonomyTree::ancestorsOf($category->id)[0] ?? $category->id);
        }

        return $roots;
    }

    /**
     * The key of the Chuyên mục screen section a category is edited in (CategorySections): the
     * catalog for a product category, else the post section that covers the category's root. Null when
     * no section shows it (the screen then opens on its default).
     *
     * @param  array<int, int>  $roots  category id → its root id, from rootsOf()
     */
    private function sectionOf(Taxonomy $category, array $roots): ?string
    {
        if ($category->vocabulary === CategorySections::PRODUCT_VOCABULARY) {
            return CategorySections::CATALOG;
        }

        $root = $roots[$category->id] ?? $category->id;
        foreach ($this->sections->postSections() as $section) {
            if (in_array($root, $section['roots'], true)) {
                return $section['key'];
            }
        }

        return null;
    }

    /**
     * @return array{kind: string, role: string, label: string, url: string|null, sub: string|null}
     */
    private function entryUsage(Entry $entry, string $role, string $locale): array
    {
        $translation = $entry->translationFor($locale);

        return [
            'kind' => $entry->type === 'page' ? 'page' : 'post',
            'role' => $role,
            'label' => $translation !== null ? $translation->title : __('entries.untitled'),
            'url' => route('admin.entries.edit', ['ulid' => $entry->ulid]),
            'sub' => $translation !== null && $translation->slug !== '' ? '/'.$translation->slug : null,
        ];
    }

    /**
     * Constrain a media query to used (true) / unused (false): used = in a product gallery, an entry
     * cover, an entry body, a block slot or a shared block's body, a branch or factory, a category's picture or one of the
     * site's brand images. The sub-selects are themselves site-scoped, so this never leaks across
     * tenants.
     *
     * $liveOwners (the library's «Dùng ở / Chưa dùng» filter): a use counts only while its owner is
     * live and in the shape countsFor() and usagesFor() read it, so a file used only by a product or
     * post in the Trash is «Chưa dùng» on its tile, in the filter and in its drawer alike. Without it
     * (who may send a file to the Trash) any row naming the file keeps it, the owner in the Trash too:
     * restoring that product or post still finds its picture.
     *
     * An entry's featured picture is read from its "in use" row (EntryCoverUsage), so the filter
     * scans no entry's meta; the protective reading (without $liveOwners) also reads the meta keys.
     *
     * @param  Builder<Media>  $query
     */
    public function constrain(Builder $query, bool $used, bool $liveOwners = false): void
    {
        $galleryMediaIds = ProductImage::query()->select('media_id');
        if ($liveOwners) {
            $galleryMediaIds->whereIn('product_id', Product::query()->select('id'));
        }
        // An entry's featured picture: its "in use" row (collection cover) for a live entry. Without
        // $liveOwners the meta keys are read too, so a cover set by a road that skipped the model (a
        // raw import before the next reconcile) still keeps its file out of the Trash, the meta of a
        // post in the Trash too, so restoring it still finds its picture.
        $coverKeys = $liveOwners ? null : Entry::withTrashed()->whereNotNull('meta->image_url')->select(DB::raw("meta->>'image_url'"));
        $blockMediaIds = $liveOwners
            ? Mediable::query()->whereIn('collection', [BlockMediaUsage::COLLECTION, EntryCoverUsage::COLLECTION])->where('mediable_type', 'entry')
                ->whereIn('mediable_id', Entry::query()->select('id'))->select('media_id')
                ->unionAll(Mediable::query()->where('collection', BlockMediaUsage::COLLECTION)->where('mediable_type', 'taxonomy')
                    ->whereIn('mediable_id', Taxonomy::query()->select('id'))->select('media_id'))
                ->unionAll(Mediable::query()->where('collection', BlockMediaUsage::COLLECTION)->where('mediable_type', ContentBlockMediaReconciler::OWNER)
                    ->whereIn('mediable_id', ContentBlock::query()->select('id'))->select('media_id'))
                ->unionAll(Mediable::query()->whereIn('collection', self::COLLECTIONS)->where('mediable_type', 'site_setting')->select('media_id'))
            : Mediable::query()->whereIn('collection', [...self::COLLECTIONS, EntryCoverUsage::COLLECTION])->select('media_id');
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
                    ->when($coverKeys !== null, fn (Builder $q) => $q->orWhereIn('storage_key', $coverKeys))
                    ->orWhereIn('id', $blockMediaIds)
                    ->orWhereIn('id', $categoryMediaIds)
                    ->orWhereIn('id', $brandMediaIds);
            });

            return;
        }

        $query->whereNotIn('id', $galleryMediaIds)
            ->when($coverKeys !== null, fn (Builder $q) => $q->whereNotIn('storage_key', $coverKeys))
            ->whereNotIn('id', $blockMediaIds)
            ->whereNotIn('id', $categoryMediaIds)
            ->whereNotIn('id', $brandMediaIds);
    }
}
