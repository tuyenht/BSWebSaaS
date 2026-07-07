<?php

namespace App\Modules\Theme\Rendering;

use App\Modules\Media\Services\MediaLibraryUrls;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaImages;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Renders a Page-JSON body ({"blocks":[{type, props}, …]}) to SSR HTML. Each block
 * is dispatched to its registered Component (static or data-bound); the component
 * resolves its view data, then the theme section partial renders it. Theme runs NO
 * customer code (ADR-04): only registered (allow-listed) types render; props are
 * Blade-escaped; unknown/malformed blocks are skipped (fail-safe).
 *
 * Image props hold a media_id (ADR-17: content never stores a URL). They are resolved to public
 * URLs in TWO passes — collect every id across the page, then one batched lookup — so a page with
 * N images costs one media query, not N (the anti-N+1 contract at tenant scale).
 */
final readonly class BlockRenderer
{
    public function __construct(
        private ViewFactory $views,
        private ComponentRegistry $registry,
        private MediaLibraryUrls $mediaUrls,
    ) {}

    public function allows(string $type): bool
    {
        return $this->registry->has($type);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function render(array $body): string
    {
        $blocks = is_array($body['blocks'] ?? null) ? $body['blocks'] : [];

        // Pass 1 — collect every media_id an image prop references across the whole page.
        $ids = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;
            if (! is_string($type) || ! $this->registry->has($type)) {
                continue;
            }

            /** @var array<string, mixed> $props */
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $ids = array_merge($ids, SchemaImages::ids($props, $this->registry->get($type)->schema()));
        }

        // One batched lookup for the whole page (site-scoped; unknown ids simply resolve to nothing).
        $urls = $this->mediaUrls->urlsForIds($ids);

        // Pass 2 — render each block, swapping each image prop's media_id for its resolved URL.
        $html = '';
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;
            if (! is_string($type) || ! $this->registry->has($type)) {
                continue; // allow-list gate
            }

            /** @var array<string, mixed> $props */
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $component = $this->registry->get($type);
            /** @var array<string, mixed> $resolved */
            $resolved = SchemaImages::replace($props, $component->schema(), $urls);

            $html .= $this->views->make($component->view(), $component->data($resolved))->render();
        }

        return $html;
    }
}
