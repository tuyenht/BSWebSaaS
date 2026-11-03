<?php

namespace App\Modules\Theme\Rendering;

use App\Modules\Media\Services\MediaLibraryUrls;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaImages;
use App\Modules\Theme\Components\StyleProps;
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
    /**
     * Content images render at one whitelisted width — sized, not the raw master, so the storefront
     * stays within its LCP/CWV budget. A single width is a compromise across hero/about/logo; proper
     * per-context srcset is the follow-up. 1280 balances mobile DPR with desktop crispness (and caps,
     * never upscaling, a smaller master).
     */
    private const int CONTENT_IMAGE_WIDTH = 1280;

    /**
     * The widths a full-width block image (a slide) offers the browser through `srcset`; views that
     * print one read it from `$srcsets` by the image's URL. Older views ignore it.
     */
    public const array SRCSET_WIDTHS = [640, 960, 1280, 1600, 2048];

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
     * The type of the first block that will actually render (registered, well formed), or null —
     * the layout asks this to know whether a title band leads the page and carries its h1. Pure:
     * no query.
     *
     * @param  array<string, mixed>  $body
     */
    public function leadType(array $body): ?string
    {
        foreach (is_array($body['blocks'] ?? null) ? $body['blocks'] : [] as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (is_string($type) && $this->registry->has($type)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The distinct types of the blocks that will render, in page order. Pure: no query. The layout
     * uses it to load a block's script only on the pages that have that block.
     *
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    public function types(array $body): array
    {
        $types = [];
        foreach (is_array($body['blocks'] ?? null) ? $body['blocks'] : [] as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (is_string($type) && $this->registry->has($type) && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Whether a block after the first asks for the scroll-in motion — the layout loads the small
     * sections script only then. Pure: no query.
     *
     * @param  array<string, mixed>  $body
     */
    public function moves(array $body): bool
    {
        $first = true;
        foreach (is_array($body['blocks'] ?? null) ? $body['blocks'] : [] as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (! is_string($type) || ! $this->registry->has($type)) {
                continue;
            }
            if (StyleProps::moves(is_array($block['props'] ?? null) ? $block['props'] : [], $first)) {
                return true;
            }
            $first = false;
        }

        return false;
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
            $ids = array_merge($ids, SchemaImages::ids($props, $this->registry->schemaFor($type)));
        }

        // One batched lookup for the whole page (site-scoped; unknown ids simply resolve to nothing).
        // The same rows give each URL its srcset (server-built from storage keys, never from props) and
        // its alt text from the library.
        ['urls' => $urls, 'srcsets' => $srcsets, 'alts' => $alts] = $this->mediaUrls->resolve($ids, self::CONTENT_IMAGE_WIDTH, self::SRCSET_WIDTHS);

        // Pass 2 — render each block, swapping each image prop's media_id for its resolved URL, inside
        // the look wrapper when the block sets one (StyleProps).
        $html = '';
        $first = true;
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
            $resolved = SchemaImages::replace($props, $this->registry->schemaFor($type), $urls);

            /** @var view-string $view a theme section view (ComponentRegistry) */
            $view = $component->view();
            $section = $this->views->make($view, $component->data($resolved) + ['srcsets' => $srcsets, 'alts' => $alts])->render();

            $image = $resolved['bg_image'] ?? null;
            $wrapper = StyleProps::wrapper($props, $first, is_string($image) ? $image : null);
            $html .= $wrapper === null ? $section : $this->views->make('theme::partials.sf-section', [
                'wrapper' => $wrapper,
                'section' => $section,
                'srcset' => $wrapper['image'] !== null ? ($srcsets[$wrapper['image']] ?? null) : null,
                'eager' => $first,
            ])->render();
            $first = false;
        }

        return $html;
    }
}
