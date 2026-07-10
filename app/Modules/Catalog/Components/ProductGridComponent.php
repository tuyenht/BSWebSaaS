<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Components;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Contracts\Component;

/**
 * A DATA-BOUND grid of published catalog products, rendered as cards (image / name / summary) on the
 * shared grid section. Auto-scoped to the current site (RLS). Reuses the entry-grid card view — a
 * product card carries the same shape (title / url / image / short outcome) minus the sector badge.
 * URLs come from the STORED product routes in ONE batched lookup, so the grid links exactly where
 * the resolver serves (matching the entry grid, no N+1).
 */
final readonly class ProductGridComponent implements Component
{
    /** Cards display ~480px wide; 640 (next Worker-whitelist width) keeps them crisp on retina. */
    private const int CARD_WIDTH = 640;

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

        $products = Product::query()
            ->where('status', 'published')
            ->with(['translations', 'images.media'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        // Stored routes in one batched lookup (no N+1) — the grid links exactly where the resolver serves.
        $paths = SiteRoute::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->where('locale', $locale)
            ->pluck('path', 'product_id');

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

        return ['props' => $props, 'items' => $items];
    }
}
