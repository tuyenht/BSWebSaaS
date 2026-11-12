<?php

namespace App\Modules\Theme\Rendering;

use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VideoEmbed;
use App\Modules\Theme\Chrome\BranchMap;
use App\Modules\Theme\Chrome\StorefrontChrome;
use App\Modules\Theme\Components\ListDisplay;
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
    /** Ids the product layout itself prints (never made into a part's anchor). */
    private const array PRODUCT_IDS = ['thong-tin-san-pham', 'san-pham-cung-nhom'];

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
     * @param  array{title?: string|null, description?: string|null, home?: bool, pageTitle?: string}  $seo  `home`: the site's home page
     * @param  string|null  $categoryTemplate  per-category layout name (override → category → type)
     * @param  array<string, mixed>  $extra  extra layout vars (e.g. a post's category breadcrumb)
     */
    public function render(string $type, ?string $templateOverride, string $title, array $body, array $seo = [], ?string $categoryTemplate = null, array $extra = []): string
    {
        /** @var view-string $layout a theme layout name (TemplateResolver) */
        $layout = $this->templates->layoutFor($type, $templateOverride, $categoryTemplate);
        $leadBlock = $this->blocks->leadType($body);
        // The blocks of the slots around the page (LayoutSlots) are drawn with the body; the scripts
        // they need load like the body's own.
        $slots = self::slots($extra);
        unset($extra['slotBlocks'], $extra['slotContext']);
        // The menu items that link to a level above the page (its breadcrumb) are marked too.
        $extra = StorefrontChrome::withTrail($extra);
        $scripts = $slots['blocks'] === [] ? $body : ['blocks' => [...array_values(is_array($body['blocks'] ?? null) ? $body['blocks'] : []), ...self::slotBlockList($slots['blocks'])]];
        $extra += in_array('hero_slider', $this->blocks->types($scripts), true) ? self::sliderHints($scripts, $leadBlock, $this->media) : [];
        // The slider script also drives the list rows (a list block that carries the display group).
        $extra += self::hasRails($scripts) ? ['sliderScript' => true] : [];
        // FAQPage structured data from the question blocks the page draws (body and slots).
        $extra += ($faq = self::faqLd($scripts)) !== null ? ['faqLd' => $faq] : [];
        // The scroll-in motion script loads only on a page with a block that moves.
        $extra += $this->blocks->moves($body) || ($slots['blocks'] !== [] && $this->blocks->moves(['blocks' => self::slotBlockList($slots['blocks'])], true)) ? ['sectionsScript' => true] : [];
        // The click-to-load video script loads only on a page with a video it can play.
        $extra += self::playsVideo($scripts) || $this->blocks->playsRichVideo($scripts) ? ['videoScript' => true] : [];
        // The count-up script loads only on a page with a stats block that holds a number.
        $extra += self::countsUp($scripts) ? ['statsScript' => true] : [];
        // The tab script loads only on a page with an article list that asks for tabs (it draws them
        // from two sub-categories up; the script is a few hundred bytes).
        $extra += self::hasTabs($scripts) ? ['tabsScript' => true] : [];
        // The branch chooser's script loads only on a page with a form that draws the branches' map.
        $extra += $this->hasBranchMap($scripts) ? ['branchMapScript' => true] : [];
        // A page led by a hero shows no breadcrumb (the hero is its opening), so its head lists none
        // either: the JSON-LD trail and the visible one always match.
        if ($type === 'page' && in_array($leadBlock, ['hero', 'hero_slider'], true)) {
            unset($extra['breadcrumb'], $extra['crumbsShown']);
        }
        $shown = $extra['crumbsShown'] ?? $extra['breadcrumb'] ?? null;
        $crumbs = is_array($shown) ? array_values($shown) : [];
        // The layout with a table of contents prints the page's days in the band that leads it.
        $withToc = $layout === 'theme::layouts.'.TemplateCatalog::PAGE_TOC;
        $dates = $withToc && is_array($extra['pageDates'] ?? null) ? $extra['pageDates'] : [];
        unset($extra['pageDates']);
        $tokens = $this->theme->cssVariables();
        // One pass over the body and the slots: pages and files are looked up once per kind. A page's layout
        // draws no photo above the body (a post, project or product has its cover), so on a page the first
        // block that draws an image takes the high priority, even after a block of text.
        $drawn = $this->blocks->renderBodies([self::withPageTitle($body, $title, $leadBlock, $crumbs, $dates), ...self::slotBodies($slots['blocks'])], $slots['context'], true, $type === 'page');
        ['html' => $sections, 'bgVideo' => $bgVideo] = $drawn[0];
        $slotHtml = self::slotHtml($slots['blocks'], array_slice($drawn, 1));
        $extra['slotHtml'] = $slotHtml;
        $bgVideo = $bgVideo || array_filter(array_slice($drawn, 1), fn (array $one): bool => $one['bgVideo']) !== [];
        // The background-video script loads only on a page where a hero's video resolved (a missing
        // or deleted video renders the plain hero, with no script).
        $extra += $bgVideo ? ['bgVideoScript' => true] : [];
        // The photo viewer loads only on a page that drew a photo opening larger (body or slots).
        $extra += self::zooms($sections, ...array_values($slotHtml)) ? ['viewerScript' => true] : [];
        // The post layout's table of contents: anchors are made here for the headings of the formatted
        // text and of the gallery, video and FAQ blocks (two or more). Its small script marks the entry
        // being read; without it the list is plain links.
        $toc = ['html' => $sections, 'items' => []];
        if ($layout === 'theme::layouts.post') {
            $toc = ArticleToc::of($sections, array_map('strval', array_keys((array) request()->attributes->get('sf_zigzag_ids', []))));
            $sections = $toc['html'];
            $extra += $toc['items'] !== [] ? ['tocScript' => true] : [];
            // The news look's reading progress line and copy-link button (a post with no address to share has neither).
            $extra += ! empty($extra['canonical']) ? ['postScript' => true] : [];
        }
        // The page layout with a table of contents: the band that leads the page stays full width above
        // the two columns, and the headings of the text after it are numbered and given anchors.
        $band = '';
        $tocOwn = false;
        if ($withToc) {
            [$band, $sections] = $leadBlock === 'page_header' ? self::splitBand($sections) : ['', $sections];
            $toc = ArticleToc::numbered($sections, array_map('strval', array_keys((array) request()->attributes->get('sf_zigzag_ids', []))));
            $sections = $toc['html'];
            $tocOwn = $toc['own'];
        }

        // Whether a section already printed a high-priority image (a hero, about, slider or a
        // first block's background, wherever it sits): a post's cover then stays at 'eager', so
        // the page asks for one high-priority image, not two. Matched on the tag: text (a post
        // about the attribute itself) cannot hold a raw "<", Blade and the purifier escape it.
        $bodyHighImage = preg_match('~<img\b[^>]*\sfetchpriority="high"~', $band.$sections) === 1;
        // A post with a cover photo (the photo that also gives it its share image) leads with that photo
        // from the media host: connect to the host with the HTML. A page's share image is not drawn.
        if ($type === 'post' && ! isset($extra['mediaOrigin']) && ! empty($extra['ogImage'])) {
            $origin = $this->media->origin();
            if ($origin !== null && $origin !== request()->getSchemeAndHttpHost()) {
                $extra['mediaOrigin'] = $origin;
            }
        }

        return BlockStyles::narrow($this->views->make($layout, $this->head($seo, $title, $extra) + [
            'title' => $title,
            'tokens' => $tokens,
            'sections' => $sections,
            'band' => $band,
            // A page_header band leading the page renders the h1 itself; the layout then skips its own.
            'leadBlock' => $leadBlock,
            'bodyHighImage' => $bodyHighImage,
            // The headings of the body's formatted text, for a post's table of contents (two or more).
            'toc' => $toc['items'],
            // The author numbered the headings: the contents list then adds no numbers of its own.
            'tocOwn' => $tocOwn,
        ] + $extra)->render());
    }

    /**
     * FAQPage JSON-LD for the `faq` blocks of a page: every question the block draws that also has
     * an answer (a question without one is not a valid Question). Null when there is none.
     *
     * @param  array<array-key, mixed>  $scripts  a body or `['blocks' => [...]]`
     * @return array<string, mixed>|null
     */
    private static function faqLd(array $scripts): ?array
    {
        $questions = [];
        foreach (is_array($scripts['blocks'] ?? null) ? $scripts['blocks'] : [] as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'faq' || ! is_array($block['props'] ?? null)) {
                continue;
            }
            foreach (SectionProps::items($block['props'], 'items') as $item) {
                $q = SectionProps::text($item, 'q');
                $a = SectionProps::text($item, 'a');
                if ($q !== '' && $a !== '') {
                    $questions[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
                }
            }
        }

        return $questions === [] ? null : ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $questions];
    }

    /**
     * The slot blocks and the page data they draw, as the controller handed them in `$extra`.
     *
     * @param  array<string, mixed>  $extra
     * @return array{blocks: array<string, list<array<array-key, mixed>>>, context: array<array-key, mixed>}
     */
    private static function slots(array $extra): array
    {
        $blocks = [];
        foreach (is_array($extra['slotBlocks'] ?? null) ? $extra['slotBlocks'] : [] as $slot => $list) {
            $kept = array_values(array_filter(is_array($list) ? $list : [], 'is_array'));
            if (is_string($slot) && $kept !== []) {
                $blocks[$slot] = $kept;
            }
        }

        return ['blocks' => $blocks, 'context' => is_array($extra['slotContext'] ?? null) ? $extra['slotContext'] : []];
    }

    /**
     * @param  array<string, list<array<array-key, mixed>>>  $blocks
     * @return list<mixed>
     */
    private static function slotBlockList(array $blocks): array
    {
        return array_merge([], ...array_values($blocks));
    }

    /**
     * @param  array<string, list<array<array-key, mixed>>>  $blocks
     * @return list<array{blocks: list<array<array-key, mixed>>}>
     */
    private static function slotBodies(array $blocks): array
    {
        return array_map(fn (array $list): array => ['blocks' => $list], array_values($blocks));
    }

    /**
     * @param  array<string, list<array<array-key, mixed>>>  $blocks
     * @param  list<array{html: string, bgVideo: bool}>  $drawn
     * @return array<string, string> slot → its HTML (only the slots that drew something)
     */
    private static function slotHtml(array $blocks, array $drawn): array
    {
        $html = [];
        foreach (array_keys($blocks) as $index => $slot) {
            if (trim($drawn[$index]['html']) !== '') {
                $html[$slot] = $drawn[$index]['html'];
            }
        }

        return $html;
    }

    /**
     * Draw the slots of a page that has no body of its own (a list, a product): the slot HTML and
     * the scripts its blocks need, added to the layout's variables. Takes `slotBlocks` and
     * `slotContext` out of $extra; with no slot blocks it changes nothing else.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function withSlots(array $extra): array
    {
        $slots = self::slots($extra);
        unset($extra['slotBlocks'], $extra['slotContext']);
        if ($slots['blocks'] === []) {
            return $extra;
        }

        $scripts = ['blocks' => self::slotBlockList($slots['blocks'])];
        $drawn = $this->blocks->renderBodies(self::slotBodies($slots['blocks']), $slots['context'], false);
        $slotHtml = self::slotHtml($slots['blocks'], $drawn);
        $extra['slotHtml'] = $slotHtml;
        $flags = [
            // A slider with two or more slides rotates, and a list block's rows slide: the same checks as a body's.
            'sliderScript' => self::hasRails($scripts) || (in_array('hero_slider', $this->blocks->types($scripts), true) && (self::sliderHints($scripts, null, $this->media)['sliderScript'] ?? false)),
            'sectionsScript' => $this->blocks->moves($scripts, true),
            'videoScript' => self::playsVideo($scripts) || $this->blocks->playsRichVideo($scripts),
            'statsScript' => self::countsUp($scripts),
            'tabsScript' => self::hasTabs($scripts),
            'branchMapScript' => $this->hasBranchMap($scripts),
            'bgVideoScript' => array_filter($drawn, fn (array $one): bool => $one['bgVideo']) !== [],
            'viewerScript' => self::zooms(...array_values($slotHtml)),
        ];
        foreach ($flags as $key => $on) {
            if ($on) {
                $extra[$key] = true;
            }
        }

        return $extra;
    }

    /**
     * The head's search lines for a page (SiteSeo): the <title> (its own Google title, the site's
     * default title on the home page, else the title template), the description (its own, else the
     * site's default in the page's language; the head falls back to the brand description), and
     * `noindex, nofollow` on every page while the site is hidden from search engines (over any
     * robots line the page asked for). The page's language and the site's name come from the
     * chrome the controller already resolved: no query here.
     *
     * @param  array{title?: string|null, description?: string|null, home?: bool, pageTitle?: string}  $seo  `pageTitle`: the name the title template is given when it differs from the heading (a parent added to keep titles unique)
     * @param  array<string, mixed>  $extra
     * @return array{seoTitle: string, seoDescription: string|null, robots?: string}
     */
    private function head(array $seo, string $title, array $extra): array
    {
        $locale = Cast::string($extra['lang'] ?? null, app()->getLocale());
        $head = [
            'seoTitle' => $this->seo->title($seo['title'] ?? null, $seo['pageTitle'] ?? $title, $seo['home'] ?? false, $locale, Cast::string(data_get($extra, 'brand.name'))),
            'seoDescription' => $this->seo->description($seo['description'] ?? null, $locale),
        ];
        if (! $this->seo->indexable()) {
            $head['robots'] = SiteSeo::HIDDEN;
        }

        return $head;
    }

    /**
     * Whether an article list on the page asks for tabs (a card look with `tabs` other than `off`; the
     * plain list and the rows never draw tabs, so they do not load the script).
     * Pure: no query.
     *
     * @param  array<string, mixed>  $body
     */
    private static function hasTabs(array $body): bool
    {
        foreach (is_array($body['blocks'] ?? null) ? $body['blocks'] : [] as $block) {
            $props = is_array($block) && ($block['type'] ?? null) === 'entry_grid' && is_array($block['props'] ?? null) ? $block['props'] : null;
            if ($props === null) {
                continue;
            }
            $variant = $props['variant'] ?? 'list';
            $tabs = $props['tabs'] ?? 'off';
            // The job look's filter buttons use the same script (no tab panels, one grid).
            if ((in_array($variant, SectionProps::TAB_VARIANTS, true) && in_array($tabs, ['auto', 'filter'], true)) || ($variant === 'job' && $tabs === 'filter')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a form block on the page draws the branches' map (`map_source: branches` and at least one
     * branch that can be mapped; otherwise the form draws its map address), the only place the chooser's
     * script has work. No query: the places come from the request's memoized settings.
     *
     * @param  array<string, mixed>  $body
     */
    private function hasBranchMap(array $body): bool
    {
        foreach (is_array($body['blocks'] ?? null) ? $body['blocks'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'form' && is_array($block['props'] ?? null) && ($block['props']['map_source'] ?? null) === 'branches') {
                return app(BranchMap::class)->places(app()->getLocale()) !== [];
            }
        }

        return false;
    }

    /**
     * Whether a list block on the page carries the display group (a block saved before it has no
     * `layout` key and draws as it always did, with no script). Pure: no query.
     *
     * @param  array<string, mixed>  $body
     */
    private static function hasRails(array $body): bool
    {
        foreach (is_array($body['blocks'] ?? null) ? $body['blocks'] : [] as $block) {
            if (is_array($block) && in_array($block['type'] ?? null, ['entry_grid', 'product_grid', 'partners', 'testimonial', 'team', 'branches'], true)
                && is_array($block['props'] ?? null) && ListDisplay::applies($block['props'])) {
                return true;
            }
        }

        return false;
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
     * Whether drawn HTML holds a photo that opens larger (a link marked `data-sf-zoom`, printed only by
     * the theme's gallery and about views). Matched on the tag, as `bodyHighImage` is: text cannot
     * hold a raw "<", and the purifier drops data attributes from formatted text.
     */
    private static function zooms(string ...$html): bool
    {
        foreach ($html as $one) {
            if (preg_match('~<a\b[^>]*\sdata-sf-zoom=~', $one) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a stats block (or the stats beside a call to action) on the page holds at least one
     * counted number, the only thing the count-up script animates: a year is printed as is. Pure: no query.
     *
     * @param  array<string, mixed>  $body
     */
    private static function countsUp(array $body): bool
    {
        foreach (is_array($body['blocks'] ?? null) ? $body['blocks'] : [] as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            $props = is_array($block) && is_array($block['props'] ?? null) ? $block['props'] : [];
            $key = match (true) {
                $type === 'stats' => 'items',
                $type === 'cta' && ($props['side'] ?? null) === 'stats' && SectionProps::choice($props, 'variant', SectionProps::CTA_VARIANTS) === 'default' => 'side_items',
                default => null,
            };
            $max = $type === 'stats' ? SectionProps::STATS_MAX_ITEMS : SectionProps::CTA_SIDE_MAX_ITEMS;
            foreach ($key === null ? [] : SectionProps::items($props, $key, $max) as $item) {
                if (SectionProps::choice($item, 'kind', SectionProps::STAT_KINDS) === 'number' && SectionProps::statNumber($item['value'] ?? null) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Prepare the page_header blocks: one without its own title shows the page title, and only
     * the band that leads the page is marked as the page's h1 (`_lead`) and carries the page's
     * breadcrumb (`_crumbs`) and, on the layout with a table of contents, the page's days (`_dates`)
     * — any later band is an h2 without them. All are always set here, so a stored value never counts.
     *
     * @param  array<string, mixed>  $body
     * @param  list<mixed>  $crumbs  the page's breadcrumb trail (Home → … → this page)
     * @param  array<array-key, mixed>  $dates  the page's days (PostDetail::dates shape), or none
     * @return array<string, mixed>
     */
    private static function withPageTitle(array $body, string $title, ?string $leadType, array $crumbs = [], array $dates = []): array
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
            $props['_crumbs'] = $isLead ? $crumbs : [];
            $props['_dates'] = $isLead ? $dates : [];
            $block['props'] = $props;
            $blocks[$index] = $block;
        }
        $body['blocks'] = $blocks;

        return $body;
    }

    /**
     * The title band that leads the page (its first block, bare or in its look wrapper) and the rest
     * of the page's sections. Both views are the theme's own, so the band closes at its first
     * `</section>` (it holds no section), followed by the wrapper's `</div>` when it has one.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitBand(string $sections): array
    {
        if (preg_match('~^\s*(?:<div class="sf-s[^"]*"[^>]*>.*?)?<section class="page-band[^"]*">.*?</section>~s', $sections, $band) !== 1) {
            return ['', $sections];
        }
        $end = strlen($band[0]);
        if (str_starts_with(ltrim($band[0]), '<div') && preg_match('~\G\s*</div>~', $sections, $close, 0, $end) === 1) {
            $end += strlen($close[0]);
        }

        return [substr($sections, 0, $end), substr($sections, $end)];
    }

    /**
     * Render a category-list page: title + the (already-resolved) posts list + simple
     * prev/next pagination, on the category layout (taxonomy list_template → category →
     * theme). Like render(), takes primitives so Theme stays independent of Cms.
     *
     * @param  list<array{title: string, url: string}>  $posts
     * @param  array{prevUrl?: string|null, nextUrl?: string|null}  $pagination
     * @param  array{title?: string|null, description?: string|null, pageTitle?: string}  $seo
     * @param  array<string, mixed>  $extra  extra layout vars (e.g. the resolved nav menu)
     */
    public function renderCategory(?string $listTemplate, string $title, array $posts, array $pagination = [], array $seo = [], array $extra = []): string
    {
        /** @var view-string $layout a theme layout name (TemplateResolver) */
        $layout = $this->templates->layoutFor('category', null, $listTemplate);
        $extra = StorefrontChrome::withTrail($this->withSlots($extra));

        return BlockStyles::narrow($this->views->make($layout, $this->head($seo, $title, $extra) + [
            'title' => $title,
            'tokens' => $this->theme->cssVariables(),
            'posts' => $posts,
            'pagination' => $pagination,
            'ldPage' => 'CollectionPage',
        ] + $extra)->render());
    }

    /**
     * Render a product detail page: name + description HTML + gallery, on the product layout
     * (theme::layouts.product). Like render()/renderCategory(), takes PRIMITIVES (a pre-sanitized
     * description string + resolved image URLs) so the Theme module stays independent of Cms and
     * Catalog.
     *
     * @param  list<array{url: string, alt: string}>  $images
     * @param  array{title?: string|null, description?: string|null, pageTitle?: string}  $seo
     * @param  array<string, mixed>  $extra  extra layout vars (chrome, canonical, og:*, breadcrumb)
     */
    public function renderProduct(string $name, string $descriptionHtml, array $images, array $seo = [], array $extra = []): string
    {
        /** @var view-string $layout a theme layout name (TemplateResolver) */
        $layout = $this->templates->layoutFor('product');
        $extra = StorefrontChrome::withTrail($this->withSlots($extra));
        // Videos stored in the description render as the click-to-load façade (VideoFacades).
        ['html' => $descriptionHtml, 'videos' => $videos] = $this->videos->render($descriptionHtml);
        // A specification table gets the same narrow-column layout as one in a post body.
        $descriptionHtml = ProseTables::apply($descriptionHtml);
        // The description is drawn as a formatted-text section (the shape the section bar reads), and
        // the site's shared parts (the product_tabs slot) follow it. With two or more headings each
        // one gets an anchor and a link on the bar; the bar's script marks the part being read.
        $slotHtml = is_array($extra['slotHtml'] ?? null) ? $extra['slotHtml'] : [];
        $shared = Cast::string($slotHtml['product_tabs'] ?? '');
        $parts = ArticleToc::parts($descriptionHtml, $shared, self::PRODUCT_IDS);

        return BlockStyles::narrow($this->views->make($layout, $this->head($seo, $name, $extra) + [
            'title' => $name,
            'tokens' => $this->theme->cssVariables(),
            'description' => $parts['html'],
            'parts' => $parts['items'],
            'images' => $images,
            'ldPage' => 'ItemPage',
        ] + ($videos > 0 ? ['videoScript' => true] : []) + ($parts['items'] !== [] ? ['tocScript' => true] : []) + $extra)->render());
    }

    /**
     * Render a page with nothing of the site's content to show — the 404 page, or the page of a
     * site that is not open — on its own layout (theme::layouts.not-found), in the same header,
     * footer and tokens as every page. The <title> runs through the site's title template like any
     * inner page, and the head carries whatever robots line the caller set (callers ask for
     * `noindex`; a site hidden from search engines keeps its own, stricter one).
     *
     * @param  array<string, mixed>  $extra  the page chrome, the message (`notice`, the 404's when absent) and the suggested links
     */
    public function renderNotice(string $title, array $extra = []): string
    {
        return BlockStyles::narrow($this->views->make('theme::layouts.not-found', $this->head([], $title, $extra) + [
            'title' => $title,
            'tokens' => $this->theme->cssVariables(),
            // The head leaves out the share tags and the JSON-LD: there is no page to describe.
            'notFound' => true,
        ] + $extra)->render());
    }
}
