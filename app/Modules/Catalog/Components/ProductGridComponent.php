<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Components;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Contracts\Component;
use App\Modules\Shared\Support\Cast;
use App\Modules\Theme\Components\ListDisplay;
use App\Modules\Theme\Components\SectionProps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A DATA-BOUND grid of published catalog products, rendered as cards (image / name / summary) on the
 * shared grid section. Auto-scoped to the current site (RLS). Reuses the entry-grid card view — a
 * product card carries the same shape (title / url / image / short outcome) minus the sector badge.
 * URLs come from the STORED product routes in ONE batched lookup, so the grid links exactly where
 * the resolver serves (matching the entry grid, no N+1).
 *
 * The `overlay` variant shows each product as a photo card with its category over it (the shared
 * card partial); it adds the products' categories and their names to the same batch (two reads).
 */
final readonly class ProductGridComponent implements Component
{
    /** Cards display ~480px wide; 640 (next Worker-whitelist width) keeps them crisp on retina. */
    private const int CARD_WIDTH = 640;

    /** The widths a card photo offers through srcset. */
    private const array CARD_SRCSET_WIDTHS = [320, 640, 960, 1280];

    public function __construct(private MediaUrlResolver $media) {}

    public function view(): string
    {
        return 'theme::sections.entry_grid';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schema(): array
    {
        // `locale` is server-resolved (site default), not author-set → not in the schema.
        return [
            'heading' => ['type' => 'text'],
            'limit' => ['type' => 'int', 'default' => 6, 'min' => ListDisplay::LIMIT_MIN, 'max' => ListDisplay::LIMIT_MAX],
            'variant' => ['type' => 'enum', 'options' => SectionProps::PRODUCT_GRID_VARIANTS, 'default' => 'grid'],
            'source' => ['type' => 'enum', 'options' => SectionProps::PRODUCT_SOURCES, 'default' => 'latest'],
            'sort' => ['type' => 'enum', 'options' => SectionProps::PRODUCT_SORTS, 'default' => 'newest'],
            // The category's ulid (never its id): renaming the category or its path keeps the block right.
            'taxonomy_key' => ['type' => 'text', 'pattern' => SectionProps::ULID_PATTERN],
            // The hand-picked products' ulids, in the order to show them.
            'product_keys' => ['type' => 'list', 'of' => ['type' => 'text', 'pattern' => SectionProps::ULID_PATTERN], 'max' => SectionProps::PRODUCT_PICK_MAX],
            'description' => ['type' => 'textarea'],
            'view_all_label' => ['type' => 'text'],
            'view_all_url' => ['type' => 'url'],
        ] + SectionProps::LIST_DISPLAY;
    }

    /**
     * @param  array<string, mixed>  $props  supports: heading, limit, locale
     * @return array<string, mixed>
     */
    public function data(array $props): array
    {
        // Coerce like EntryGridComponent: number inputs hydrate as strings; clamp 1..50 so a
        // careless/forged limit can't issue an unbounded query.
        $rawLimit = $props['limit'] ?? 6;
        $limit = is_numeric($rawLimit) ? min(ListDisplay::LIMIT_MAX, max(ListDisplay::LIMIT_MIN, (int) $rawLimit)) : 6;
        $locale = is_string($props['locale'] ?? null) ? $props['locale'] : 'vi';
        $variant = SectionProps::choice($props, 'variant', SectionProps::PRODUCT_GRID_VARIANTS);

        $source = SectionProps::choice($props, 'source', SectionProps::PRODUCT_SOURCES);
        $keys = $source === 'picked' ? self::keys($props['product_keys'] ?? null) : [];
        // A hand-picked list shows every product on it (at most the pick limit): its length is the count.
        if ($source === 'picked') {
            $limit = max(1, count($keys));
        }

        $products = $this->products($props, $source, $keys)
            ->with($variant === 'overlay' ? ['translations', 'images.media', 'taxonomies.translations'] : ['translations', 'images.media'])
            // One row more than shown when the block carries the display group: it tells "view all"
            // there is more, with no COUNT(*). A picked list has no "more".
            ->limit($limit + ($source !== 'picked' && ListDisplay::applies($props) ? 1 : 0))
            ->get();
        if ($source === 'picked') {
            // The list's own order; a product that is gone or not published is not in the result.
            $order = array_flip($keys);
            $products = $products->sortBy(fn (Product $product): int => $order[$product->ulid] ?? PHP_INT_MAX)->values();
        }

        $display = ListDisplay::plan($variant === 'overlay' ? 'product-overlay' : 'product', $props, $products->count(), $limit);
        if ($display !== null) {
            $products = $products->take($display['shown']);
        } elseif ($products->count() > $limit) {
            $products = $products->take($limit);
        }

        // Stored routes in one batched lookup (no N+1) — the grid links exactly where the resolver serves.
        $paths = SiteRoute::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->where('locale', $locale)
            ->pluck('path', 'product_id');

        if ($variant === 'overlay') {
            return ['props' => $props, 'items' => $this->cards($products, $locale, $paths), 'variant' => $variant, 'display' => $display];
        }

        $items = $products->map(function (Product $product) use ($locale, $paths): array {
            $translation = $product->translationFor($locale);
            $media = $product->images->first()?->media;
            $image = $media?->storage_key;
            $path = $paths->get($product->id);

            return [
                'title' => $translation !== null ? $translation->name : '',
                'url' => is_string($path) ? $path : '',
                'image' => is_string($image) && $image !== '' ? $this->media->url($image, self::CARD_WIDTH) : '',
                'srcset' => is_string($image) && $image !== '' && $media !== null ? $this->media->srcset($image, self::CARD_SRCSET_WIDTHS, $media->width) : null,
                'short_outcome' => $translation !== null ? ($translation->summary ?? '') : '',
                'sector' => '',
            ];
        })->all();

        return ['props' => $props, 'items' => $items, 'variant' => $variant, 'display' => $display];
    }

    /**
     * The picked keys that name no published product of this site (gone, in the trash, a draft, or
     * another site's), upper case. One query; the editor marks them.
     *
     * @return list<string>
     */
    public static function goneKeys(mixed $keys): array
    {
        $keys = self::keys($keys);
        if ($keys === []) {
            return [];
        }
        $found = Product::query()->where('status', 'published')->whereIn('ulid', $keys)->pluck('ulid')->all();

        return array_values(array_diff($keys, Cast::strings($found)));
    }

    /**
     * The valid ulids of a saved list, upper case (as stored), no repeats, at most the pick limit, in order.
     *
     * @return list<string>
     */
    private static function keys(mixed $value): array
    {
        $keys = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) && preg_match(SectionProps::ULID_PATTERN, $item) === 1) {
                $keys[strtoupper($item)] = true;
            }
        }

        return array_slice(array_keys($keys), 0, SectionProps::PRODUCT_PICK_MAX);
    }

    /**
     * The published products of the block's source, in the block's order (a picked list is put in
     * its own order after the read). All through BelongsToSite, so another site's ulid finds nothing.
     *
     * @param  array<string, mixed>  $props
     * @param  list<string>  $keys
     * @return Builder<Product>
     */
    private function products(array $props, string $source, array $keys): Builder
    {
        $query = Product::query()->where('status', 'published');

        if ($source === 'picked') {
            return $query->whereIn('ulid', $keys);
        }

        if ($source === 'category') {
            $key = $props['taxonomy_key'] ?? null;
            $key = is_string($key) && preg_match(SectionProps::ULID_PATTERN, $key) === 1 ? strtoupper($key) : null;
            // No (or a malformed) category: nothing, not the newest products under a wrong heading.
            $query = $key === null ? $query->whereRaw('false') : $query->whereHas('taxonomies', fn (Builder $category) => $category->where('taxonomies.ulid', $key));
        }

        return SectionProps::choice($props, 'sort', SectionProps::PRODUCT_SORTS) === 'oldest'
            ? $query->orderBy('published_at')->orderBy('id')
            : $query->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * The card shape the shared card partial reads (no id leaves the server).
     *
     * @param  iterable<Product>  $products
     * @param  Collection<int|string, mixed>  $paths
     * @return list<array{title: string, url: string, image: string, srcset: string|null, icon: string, excerpt: string, date: string, datetime: string, label: string}>
     */
    private function cards(iterable $products, string $locale, Collection $paths): array
    {
        $cards = [];
        foreach ($products as $product) {
            $translation = $product->translationFor($locale);
            $media = $product->images->first()?->media;
            $key = $media?->storage_key;
            $path = $paths->get($product->id);
            $category = $product->primaryCategory();

            $cards[] = [
                'title' => $translation !== null ? $translation->name : '',
                'url' => is_string($path) ? $path : '',
                'image' => is_string($key) && $key !== '' ? $this->media->url($key, self::CARD_WIDTH) : '',
                'srcset' => is_string($key) && $key !== '' ? $this->media->srcset($key, self::CARD_SRCSET_WIDTHS, $media->width) : null,
                'icon' => '',
                'excerpt' => $translation !== null ? ($translation->summary ?? '') : '',
                'date' => '',
                'datetime' => '',
                'label' => $category?->translationFor($locale)->name ?? '',
            ];
        }

        return $cards;
    }
}
