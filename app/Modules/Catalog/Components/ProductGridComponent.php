<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Components;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Contracts\Component;
use App\Modules\Theme\Components\SectionProps;
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
            'limit' => ['type' => 'int', 'default' => 6],
            'variant' => ['type' => 'enum', 'options' => SectionProps::PRODUCT_GRID_VARIANTS, 'default' => 'grid'],
            'bg' => ['type' => 'enum', 'options' => SectionProps::BG, 'default' => 'none'],
            'description' => ['type' => 'textarea'],
            'view_all_label' => ['type' => 'text'],
            'view_all_url' => ['type' => 'url'],
        ];
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
        $limit = is_numeric($rawLimit) ? min(50, max(1, (int) $rawLimit)) : 6;
        $locale = is_string($props['locale'] ?? null) ? $props['locale'] : 'vi';
        $variant = SectionProps::choice($props, 'variant', SectionProps::PRODUCT_GRID_VARIANTS);

        $products = Product::query()
            ->where('status', 'published')
            ->with($variant === 'overlay' ? ['translations', 'images.media', 'taxonomies.translations'] : ['translations', 'images.media'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        // Stored routes in one batched lookup (no N+1) — the grid links exactly where the resolver serves.
        $paths = SiteRoute::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->where('locale', $locale)
            ->pluck('path', 'product_id');

        if ($variant === 'overlay') {
            return ['props' => $props, 'items' => $this->cards($products, $locale, $paths), 'variant' => $variant];
        }

        $items = $products->map(function (Product $product) use ($locale, $paths): array {
            $translation = $product->translationFor($locale);
            $image = $product->images->first()?->media?->storage_key;
            $path = $paths->get($product->id);

            return [
                'title' => $translation !== null ? $translation->name : '',
                'url' => is_string($path) ? $path : '',
                'image' => is_string($image) && $image !== '' ? $this->media->url($image, self::CARD_WIDTH) : '',
                'short_outcome' => $translation !== null ? ($translation->summary ?? '') : '',
                'sector' => '',
            ];
        })->all();

        return ['props' => $props, 'items' => $items, 'variant' => $variant];
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
