<?php

namespace App\Modules\Theme\Rendering;

use App\Modules\Media\Services\MediaLibraryUrls;
use App\Modules\Shared\Contracts\PageLinks;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaHtml;
use App\Modules\Theme\Components\SchemaImages;
use App\Modules\Theme\Components\SectionProps;
use App\Modules\Theme\Components\StyleProps;
use App\Modules\Theme\Theming\ThemeResolver;
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
 * N images costs one media query, not N (the anti-N+1 contract at tenant scale). The library
 * pictures inside formatted text (`html` props, `img[data-media-id]`) join the same lookup, and
 * each html prop is then prepared for printing by RichHtml (pictures, video façades). Video props
 * (the hero's background video) are media ids too and join the same lookup; each resolves to its
 * file's source, or to nothing when it is not a video of this site. Button links (ButtonLink) that
 * open a page or a library file are resolved the same way: every page ulid and file key on the page,
 * then one lookup each (none when the page has no such button), and each button gets its current
 * address or none.
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
        private RichHtml $richHtml,
        private ThemeResolver $theme,
        private PageLinks $pageLinks,
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
     * Whether a formatted-text prop on the page holds a video the façade can play — the layout loads
     * the video script only then. Pure: no query.
     *
     * @param  array<string, mixed>  $body
     */
    public function playsRichVideo(array $body): bool
    {
        foreach (is_array($body['blocks'] ?? null) ? $body['blocks'] : [] as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (! is_string($type) || ! $this->registry->has($type) || ! is_array($block['props'] ?? null)) {
                continue;
            }
            foreach (SchemaHtml::values($block['props'], $this->registry->schemaFor($type)) as $html) {
                if (VideoFacades::plays($html)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function render(array $body): string
    {
        return $this->renderSections($body)['html'];
    }

    /**
     * The page's sections, and whether one of them plays a background video — a hero whose video
     * resolved to a video file of this site (a missing, deleted or foreign id renders the plain hero
     * and does not count). The layout loads the background-video script only then.
     *
     * @param  array<string, mixed>  $body
     * @return array{html: string, bgVideo: bool}
     */
    public function renderSections(array $body): array
    {
        $blocks = is_array($body['blocks'] ?? null) ? $body['blocks'] : [];

        // Pass 1 — collect every media_id an image prop references across the whole page.
        $ids = [];
        $anchors = [];
        $pageRefs = [];
        $fileRefs = [];
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
            $schema = $this->registry->schemaFor($type);
            $ids = array_merge($ids, SchemaImages::ids($props, $schema));
            foreach (SchemaHtml::values($props, $schema) as $html) {
                $ids = array_merge($ids, RichHtml::imageIds($html));
            }
            $anchor = $props['anchor_id'] ?? null;
            if (is_string($anchor) && preg_match(StyleProps::ANCHOR_PATTERN, $anchor) === 1) {
                $anchors[$anchor] = true;
            }
            $refs = ButtonLink::refs($props, $schema);
            $pageRefs = array_merge($pageRefs, $refs['pages']);
            $fileRefs = array_merge($fileRefs, $refs['files']);
        }
        // The anchors of this page's sections are taken ids: a row of a list block must not repeat one.
        request()->attributes->set('sf_zigzag_ids', $anchors + (array) request()->attributes->get('sf_zigzag_ids', []));
        $pages = $this->pageLinks->paths(array_values(array_unique($pageRefs)), app()->getLocale());
        $files = $this->mediaUrls->fileUrls(array_values(array_unique($fileRefs)), ButtonLink::DOCUMENT_MIMES);

        // One batched lookup for the whole page (site-scoped; unknown ids simply resolve to nothing).
        // The same rows give each URL its srcset (server-built from storage keys, never from props) and
        // its alt text from the library.
        ['urls' => $urls, 'srcsets' => $srcsets, 'alts' => $alts, 'dims' => $dims, 'videos' => $videos] = $this->mediaUrls->resolve($ids, self::CONTENT_IMAGE_WIDTH, self::SRCSET_WIDTHS);

        // Pass 2 — render each block, swapping each image prop's media_id for its resolved URL, inside
        // the look wrapper when the block sets one (StyleProps).
        $html = '';
        $first = true;
        $bgVideo = false;
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
            $schema = $this->registry->schemaFor($type);
            /** @var array<string, mixed> $resolved */
            $resolved = SchemaHtml::map(
                ButtonLink::resolve(SchemaImages::replaceVideos(SchemaImages::replace($props, $schema, $urls), $schema, $videos), $schema, $pages, $files),
                $schema,
                fn (string $html): string => $this->richHtml->render($html, $urls, $srcsets, $alts, $dims)['html'],
            );

            $band = $this->videoBand($resolved);
            $bgVideo = $bgVideo || $band !== [];

            /** @var view-string $view a theme section view (ComponentRegistry) */
            $view = $component->view();
            $image = $resolved['bg_image'] ?? null;
            $wrapper = StyleProps::wrapper($props, $first, is_string($image) ? $image : null);
            // The page's one high-priority image: only the first block may claim it, and a first block
            // with a background photo has already claimed it through the sf-section wrapper.
            $resolved['_high'] = $first && ($wrapper === null || $wrapper['image'] === null);
            $section = $this->views->make($view, $component->data($resolved) + ['srcsets' => $srcsets, 'alts' => $alts, 'dims' => $dims] + $band)->render();

            $html .= $wrapper === null ? $section : $this->views->make('theme::partials.sf-section', [
                'wrapper' => $wrapper,
                'section' => $section,
                'srcset' => $wrapper['image'] !== null ? ($srcsets[$wrapper['image']] ?? null) : null,
                'eager' => $first,
            ])->render();
            $first = false;
        }

        return ['html' => $html, 'bgVideo' => $bgVideo];
    }

    /**
     * For a block whose background video resolved (the hero): `videoBand`, whether the band it plays
     * on reads dark or light, so its pause/play control takes the matching look. A colour set chosen
     * in the look panel is read through StyleProps::schemeOf, like every block: the light and soft
     * sets are light, the brand set follows its text (ThemeResolver), the dark set is dark. Any other
     * `bg` paints the hero's own dark band: a photo band, and also the older `surface` / `tint`
     * values, which StyleProps::wrapper gives no colour class and the hero section does not paint
     * (storefront.css `.hero` falls back to --color-dark). A block without a resolved video gets
     * nothing (no extra work).
     *
     * @param  array<array-key, mixed>  $resolved
     * @return array{videoBand?: 'dark'|'light'}
     */
    private function videoBand(array $resolved): array
    {
        if (! is_array($resolved['bg_video'] ?? null)) {
            return [];
        }

        $set = SectionProps::choice($resolved, 'bg', StyleProps::BG) === 'scheme'
            ? StyleProps::schemeOf($resolved, 'hero')
            : 'dark';
        $dark = match ($set) {
            'light', 'soft' => false,
            'brand' => $this->theme->brandSetIsDark(),
            default => true,
        };

        return ['videoBand' => $dark ? 'dark' : 'light'];
    }
}
