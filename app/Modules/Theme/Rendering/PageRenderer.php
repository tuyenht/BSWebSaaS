<?php

namespace App\Modules\Theme\Rendering;

use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VideoEmbed;
use App\Modules\Theme\Components\SectionProps;
use App\Modules\Theme\Theming\SiteSeo;
use App\Modules\Theme\Theming\ThemeResolver;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Renders resolved content to a full SSR HTML page on the SHARED master template:
 * pick the layout (TemplateResolver), render the Page-JSON blocks (BlockRenderer),
 * apply per-site design tokens (ThemeResolver), wrap in the layout. Takes primitives,
 * not Cms models, so the Theme module stays independent of Cms. The <title>, the description and
 * the meta robots follow the site's search settings (SiteSeo): head().
 */
final readonly class PageRenderer
{
    public function __construct(
        private ViewFactory $views,
        private TemplateResolver $templates,
        private BlockRenderer $blocks,
        private ThemeResolver $theme,
        private MediaUrlResolver $media,
        private VideoFacades $videos,
        private SiteSeo $seo,
    ) {}

    /**
     * @param  array<string, mixed>  $body  the Page-JSON body
     * @param  array{title?: string|null, description?: string|null, home?: bool}  $seo  `home`: the site's home page
     * @param  string|null  $categoryTemplate  per-category layout name (override → category → type)
     * @param  array<string, mixed>  $extra  extra layout vars (e.g. a post's category breadcrumb)
     */
    public function render(string $type, ?string $templateOverride, string $title, array $body, array $seo = [], ?string $categoryTemplate = null, array $extra = []): string
    {
        /** @var view-string $layout a theme layout name (TemplateResolver) */
        $layout = $this->templates->layoutFor($type, $templateOverride, $categoryTemplate);
        $leadBlock = $this->blocks->leadType($body);
        $extra += in_array('hero_slider', $this->blocks->types($body), true) ? self::sliderHints($body, $leadBlock, $this->media) : [];
        // The scroll-in motion script loads only on a page with a block that moves.
        $extra += $this->blocks->moves($body) ? ['sectionsScript' => true] : [];
        // The click-to-load video script loads only on a page with a video it can play.
        $extra += self::playsVideo($body) || $this->blocks->playsRichVideo($body) ? ['videoScript' => true] : [];
        // The count-up script loads only on a page with a stats block that holds a number.
        $extra += self::countsUp($body) ? ['statsScript' => true] : [];
        $tokens = $this->theme->cssVariables();
        ['html' => $sections, 'bgVideo' => $bgVideo] = $this->blocks->renderSections(self::withPageTitle($body, $title, $leadBlock));
        // The background-video script loads only on a page where a hero's video resolved (a missing
        // or deleted video renders the plain hero, with no script).
        $extra += $bgVideo ? ['bgVideoScript' => true] : [];

        return $this->views->make($layout, $this->head($seo, $title, $extra) + [
            'title' => $title,
            'tokens' => $tokens,
            'sections' => $sections,
            // A page_header band leading the page renders the h1 itself; the layout then skips its own.
            'leadBlock' => $leadBlock,
        ] + $extra)->render();
    }

    /**
     * The head's search lines for a page (SiteSeo): the <title> (its own Google title, the site's
     * default title on the home page, else the title template), the description (its own, else the
     * site's default in the page's language; the head falls back to the brand description), and
     * `noindex, nofollow` on every page while the site is hidden from search engines (over any
     * robots line the page asked for). The page's language and the site's name come from the
     * chrome the controller already resolved: no query here.
     *
     * @param  array{title?: string|null, description?: string|null, home?: bool}  $seo
     * @param  array<string, mixed>  $extra
     * @return array{seoTitle: string, seoDescription: string|null, robots?: string}
     */
    private function head(array $seo, string $title, array $extra): array
    {
        $locale = Cast::string($extra['lang'] ?? null, app()->getLocale());
        $head = [
            'seoTitle' => $this->seo->title($seo['title'] ?? null, $title, $seo['home'] ?? false, $locale, Cast::string(data_get($extra, 'brand.name'))),
            'seoDescription' => $this->seo->description($seo['description'] ?? null, $locale),
        ];
        if (! $this->seo->indexable()) {
            $head['robots'] = SiteSeo::HIDDEN;
        }

        return $head;
    }

    /**
     * What the head needs for a slider: `sliderScript` when a slider on the page has two or more
     * slides to rotate (the script loads nowhere else), and `mediaOrigin` when a slider leads the
     * page with a photo on its first slide and the media host is another origin (a `preconnect`,
     * so the LCP image's connection starts with the HTML). Pages without a slider get nothing, so
     * their head is unchanged. Pure: no query.
     *
     * @param  array<string, mixed>  $body
     * @return array{sliderScript?: true, mediaOrigin?: string}
     */
    private static function sliderHints(array $body, ?string $leadType, MediaUrlResolver $media): array
    {
        $hints = [];
        $leadSeen = false;
        foreach (is_array($body['blocks'] ?? null) ? $body['blocks'] : [] as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'hero_slider') {
                continue;
            }
            $slides = array_values(array_filter(
                is_array(data_get($block, 'props.slides')) ? data_get($block, 'props.slides') : [],
                fn (mixed $slide): bool => is_array($slide) && (! empty($slide['image_url']) || (is_string($slide['heading'] ?? null) && trim($slide['heading']) !== '')),
            ));
            if (count($slides) > 1) {
                $hints['sliderScript'] = true;
            }
            if ($leadType === 'hero_slider' && ! $leadSeen) {
                $leadSeen = true;
                $origin = $slides !== [] && ! empty($slides[0]['image_url']) ? $media->origin() : null;
                if ($origin !== null && $origin !== request()->getSchemeAndHttpHost()) {
                    $hints['mediaOrigin'] = $origin;
                }
            }
        }

        return $hints;
    }

    /**
     * Whether a video block on the page holds an address VideoEmbed recognises (the block renders
     * nothing otherwise). Pure: no query.
     *
     * @param  array<string, mixed>  $body
     */
    private static function playsVideo(array $body): bool
    {
        foreach (is_array($body['blocks'] ?? null) ? $body['blocks'] : [] as $block) {
            $url = is_array($block) && ($block['type'] ?? null) === 'video' ? data_get($block, 'props.url') : null;
            if (is_string($url) && VideoEmbed::player($url) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a stats block on the page holds at least one usable number (the only thing the count-up
     * script animates). Pure: no query.
     *
     * @param  array<string, mixed>  $body
     */
    private static function countsUp(array $body): bool
    {
        foreach (is_array($body['blocks'] ?? null) ? $body['blocks'] : [] as $block) {
            $props = is_array($block) && ($block['type'] ?? null) === 'stats' ? ($block['props'] ?? null) : null;
            foreach (is_array($props) ? SectionProps::items($props, 'items', SectionProps::STATS_MAX_ITEMS) : [] as $item) {
                if (SectionProps::statNumber($item['value'] ?? null) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Prepare the page_header blocks: one without its own title shows the page title, and only
     * the band that leads the page is marked as the page's h1 (`_lead`, always set here so a
     * stored value never counts) — any later band is an h2.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withPageTitle(array $body, string $title, ?string $leadType): array
    {
        if ($leadType === null || ! is_array($body['blocks'] ?? null)) {
            return $body;
        }

        $blocks = $body['blocks'];
        $leadFound = false;
        foreach ($blocks as $index => $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                continue;
            }
            $isLead = ! $leadFound && $block['type'] === $leadType;
            $leadFound = $leadFound || $isLead;
            if ($block['type'] !== 'page_header') {
                continue;
            }

            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            if (! is_string($props['title'] ?? null) || trim($props['title']) === '') {
                $props['title'] = $title;
            }
            $props['_lead'] = $isLead;
            $block['props'] = $props;
            $blocks[$index] = $block;
        }
        $body['blocks'] = $blocks;

        return $body;
    }

    /**
     * Render a category-list page: title + the (already-resolved) posts list + simple
     * prev/next pagination, on the category layout (taxonomy list_template → category →
     * theme). Like render(), takes primitives so Theme stays independent of Cms.
     *
     * @param  list<array{title: string, url: string}>  $posts
     * @param  array{prevUrl?: string|null, nextUrl?: string|null}  $pagination
     * @param  array{title?: string|null, description?: string|null}  $seo
     * @param  array<string, mixed>  $extra  extra layout vars (e.g. the resolved nav menu)
     */
    public function renderCategory(?string $listTemplate, string $title, array $posts, array $pagination = [], array $seo = [], array $extra = []): string
    {
        /** @var view-string $layout a theme layout name (TemplateResolver) */
        $layout = $this->templates->layoutFor('category', null, $listTemplate);

        return $this->views->make($layout, $this->head($seo, $title, $extra) + [
            'title' => $title,
            'tokens' => $this->theme->cssVariables(),
            'posts' => $posts,
            'pagination' => $pagination,
        ] + $extra)->render();
    }

    /**
     * Render a product detail page: name + description HTML + gallery, on the product layout
     * (theme::layouts.product). Like render()/renderCategory(), takes PRIMITIVES (a pre-sanitized
     * description string + resolved image URLs) so the Theme module stays independent of Cms and
     * Catalog.
     *
     * @param  list<array{url: string, alt: string}>  $images
     * @param  array{title?: string|null, description?: string|null}  $seo
     * @param  array<string, mixed>  $extra  extra layout vars (chrome, canonical, og:*, breadcrumb)
     */
    public function renderProduct(string $name, string $descriptionHtml, array $images, array $seo = [], array $extra = []): string
    {
        /** @var view-string $layout a theme layout name (TemplateResolver) */
        $layout = $this->templates->layoutFor('product');
        // Videos stored in the description render as the click-to-load façade (VideoFacades).
        ['html' => $descriptionHtml, 'videos' => $videos] = $this->videos->render($descriptionHtml);

        return $this->views->make($layout, $this->head($seo, $name, $extra) + [
            'title' => $name,
            'tokens' => $this->theme->cssVariables(),
            'description' => $descriptionHtml,
            'images' => $images,
        ] + ($videos > 0 ? ['videoScript' => true] : []) + $extra)->render();
    }
}
