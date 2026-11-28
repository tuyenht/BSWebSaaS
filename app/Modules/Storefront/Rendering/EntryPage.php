<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Rendering;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\ResolvedContent;
use App\Modules\Cms\Support\EntryCover;
use App\Modules\Cms\Support\EntryShareImage;
use App\Modules\Cms\Support\EntryTags;
use App\Modules\Cms\Support\JobFacts;
use App\Modules\Cms\Support\ReusableBlockExpander;
use App\Modules\Cms\Taxonomy\CategoryTemplates;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Storefront\EdgeCache\PageEndTime;
use App\Modules\Storefront\Support\ArticleData;
use App\Modules\Storefront\Support\Breadcrumbs;
use App\Modules\Storefront\Support\JobPosting;
use App\Modules\Storefront\Support\PostDetail;
use App\Modules\Storefront\Support\SlotContext;
use App\Modules\Storefront\Support\ThankYouPages;
use App\Modules\Storefront\Support\TitleDisambiguator;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Theme\Rendering\PageRenderer;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Symfony\Component\HttpFoundation\Response;

/**
 * The storefront page of a page or post (StorefrontController for a visitor, EntryPreviewPage for
 * the admin's previews): its post band, breadcrumb, shared blocks put in, structured data and SEO
 * fields, drawn through the theme. Holds nothing of a request: everything that reads the current
 * site is resolved per call.
 */
final readonly class EntryPage
{
    public function __construct(
        private PageRenderer $renderer,
        private PathBuilder $paths,
        private MediaUrlResolver $media,
        private Breadcrumbs $crumbs,
        private PostDetail $posts,
        private CategoryTemplates $categoryTemplates,
        private JobPosting $jobs,
        private SlotContext $slots,
        private ThankYouPages $thankYou,
        private TitleDisambiguator $titles,
        private SiteTime $time,
    ) {}

    /**
     * The storefront page of a page or post in $locale at $path; $preview draws the admin's preview of
     * it (EntryPreviewPage): nothing for search engines or link previews, no view counted, a band.
     *
     * @param  array<string, mixed>  $chrome  page chrome (menu, lang, hreflang)
     */
    public function render(ResolvedContent $content, string $locale, string $path, array $chrome, bool $preview = false): Response
    {
        $entry = $content->entry;
        // the edge keeps the page no longer than the item stays on the website (a preview is never kept)
        if (! $preview) {
            app(PageEndTime::class)->until($entry->expires_at);
        }

        $categoryTemplate = null;
        $extra = $chrome;
        // The shared blocks the body (and, for a post, its slots) place are put in before anything
        // reads the blocks (ReusableBlockExpander: one query for the page). Resolved per request: it
        // keeps what it read for this request only.
        $expander = app(ReusableBlockExpander::class);
        $body = $content->translation->body;
        // The page view is counted by the visitor's browser (sf-view.js → RecordEntryView), never
        // here: a page the edge serves never reaches this code, and counting both would double it.
        $extra['viewBeacon'] = route('storefront.views.record', ['entry' => $entry->ulid], false);
        $canonical = url($this->paths->publicUrl($path, $locale));
        $extra['canonical'] = $canonical;
        $extra['ogType'] = $entry->type === 'post' ? 'article' : 'website';

        // Featured image for og:image: the key of the file the entry's cover_media_id names (read
        // with the entry, EntryCover::KEY), else the raw R2 key in meta['image_url'].
        // Resolve at 1280 (whitelisted width; Cloudflare Worker serves it via cf.image).
        // Pages without a featured image omit $extra['ogImage'] so head.blade.php
        // falls back to the site-wide brand og_image automatically.
        // An old cover value may also be a glyph name (no picture: the brand image stands in) or an
        // outside web address (kept as it is, like the cover the post itself draws); an address of
        // any other scheme names no share image.
        // The share picture the editor picked for this language comes first (EntryShareImage), then the cover.
        $shareKey = EntryShareImage::key($content->translation);
        $metaImageKey = $shareKey !== null && str_contains($shareKey, '/') ? $shareKey : EntryCover::value($entry);
        // Whether the page itself draws a cover from the media host (the share image is never drawn): the renderer
        // connects to that host early only then.
        $coverKey = EntryCover::value($entry);
        $extra['coverDrawn'] = str_contains($coverKey, '/') && ! str_contains($coverKey, '://');
        if (preg_match('~^https?://~i', $metaImageKey) === 1) {
            $extra['ogImage'] = $metaImageKey;
        } elseif (str_contains($metaImageKey, '/') && ! str_contains($metaImageKey, '://')) {
            $extra['ogImage'] = $this->media->url($metaImageKey, 1280);
        }

        // The breadcrumb trail (Home → … → self) feeds both the head's BreadcrumbList JSON-LD and
        // the on-page breadcrumb, so the two always match. The home page has none.
        $self = $this->crumbs->crumb($content->translation->title, $this->paths->publicUrl($path, $locale));

        // A post is shown under its primary category: the category drives its layout
        // (detail_template), its breadcrumb (the category and the ones above it) and the previous,
        // next and related posts. The band reads its dates, excerpt and cover photo. Pages get
        // their parent pages as breadcrumb levels instead. The Cms lookups live here so the Theme
        // module stays Cms-agnostic.
        if ($entry->type === 'post') {
            $category = $entry->primaryCategory();
            // The category's own layout, or the one of the category above when it follows its parent;
            // a layout of the wrong role, or one the theme lacks, falls back like null.
            $categoryTemplate = $category !== null ? $this->categoryTemplates->detail($category) : null;
            $extra['post'] = $this->posts->band($entry, $content->translation, $locale);
            // its tags named in this language, each linking to the tag's page
            // resolved per request: it reads the current site, which a controller kept between requests must not hold
            $extra['tags'] = app(EntryTags::class)->links($entry, $locale);

            // A post on a job layout carries its deadline state and the JobPosting data for the head.
            $own = $entry->template_override;
            $postTemplate = $own !== null && TemplateCatalog::fits($own, TemplateCatalog::ROLE_POST) ? $own : $categoryTemplate;
            $brand = data_get($chrome, 'brand');
            $job = $this->jobs->forPost($entry, $content->translation, $postTemplate, $canonical, is_array($brand) ? $brand : null);
            if ($job !== null) {
                $extra['deadline'] = $job['state'];
                $extra['post'] += JobFacts::read($entry->meta, $locale);
                if ($job['ld'] !== null) {
                    $extra['jobLd'] = $job['ld'];
                }
            } else {
                $extra['articleLd'] = ArticleData::ld(
                    $entry, $content->translation, $canonical, url($this->paths->publicUrl('/', $locale)), $locale,
                    is_string($extra['ogImage'] ?? null) ? $extra['ogImage'] : null, is_array($brand) ? $brand : null,
                    // The capability look prints no author line, so its data names no byline either.
                    $postTemplate !== 'capability',
                    $this->time->zone(),
                );
            }

            // The blocks of the side column and of the band below the post; the newest posts of the
            // post's own category are read with the related posts only when a block asks for them.
            ['body' => $body, 'slots' => $slotBlocks] = $expander->expand($body, $this->slots->blocks(SlotContext::POST_SLOTS, $locale, $category), $locale);
            $extra['slotBlocks'] = $slotBlocks;
            $withRecent = SlotContext::has($slotBlocks, 'recent_posts');
            $brandData = is_array($brand) ? $brand : null;

            $categoryTranslation = $category?->translationFor($locale);
            if ($category !== null && $categoryTranslation !== null) {
                $extra['category'] = [
                    'name' => $categoryTranslation->name,
                    'url' => $this->paths->publicUrl($this->paths->forTaxonomy($categoryTranslation->slug), $locale),
                ];
                $extra['breadcrumb'] = [
                    $this->crumbs->home($locale),
                    ...$this->crumbs->category($category, $locale, $categoryTranslation->name, $categoryTranslation->slug),
                    $self,
                ];
                $extra['crumbsShown'] = $this->crumbs->visible($extra['breadcrumb'], true);
                // The capability layout draws neither neighbours nor related cards: read them only when a block needs the recent posts.
                if ($postTemplate !== 'capability' || $withRecent) {
                    $extra += $this->posts->siblings($entry, $category, $locale, true, $withRecent);
                }
            }
            $extra['slotContext'] = $this->slots->context($slotBlocks, $category, $locale, $brandData, is_array($extra['recent'] ?? null) ? $extra['recent'] : [], $entry->id);
            unset($extra['recent']);
        } else {
            $body = $expander->expand($body, [], $locale)['body'];
            if ($path !== '/') {
                $extra['breadcrumb'] = [$this->crumbs->home($locale), ...$this->crumbs->parentPages($entry, $locale), $self];
                $extra['crumbsShown'] = $this->crumbs->visible($extra['breadcrumb'], false);
            }
        }

        // A page on the layout with a table of contents shows the day it took effect (and the day it
        // last changed) in its title band.
        if ($entry->type === 'page' && $entry->template_override === TemplateCatalog::PAGE_TOC) {
            $extra['pageDates'] = $this->posts->dates($entry, $content->translation, $locale);
        }

        // A form's thank-you page is only for someone who just sent it: kept out of search results.
        if ($entry->type === 'page' && $path !== '/' && $this->thankYou->has($entry->ulid)) {
            $extra['robots'] = 'noindex';
        }

        // The home page names the website itself: the name search results show for the site.
        $brandName = data_get($chrome, 'brand.name');
        if ($path === '/' && is_string($brandName) && trim($brandName) !== '') {
            $extra['websiteLd'] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => trim($brandName), 'url' => $canonical, 'inLanguage' => $locale];
        }

        // A preview (preview()) prints none of what tells search engines or link previews about a page
        // (no canonical, share image or JSON-LD: the head leaves them out), counts no view, asks not to
        // be indexed and says on the page that it is a preview.
        if ($preview) {
            unset($extra['viewBeacon'], $extra['canonical'], $extra['ogImage']);
            $extra['preview'] = true;
            $extra['previewLive'] = $entry->status === 'published';
            $extra['robots'] = 'noindex, nofollow';
        }

        $html = $this->renderer->render(
            $entry->type,
            $entry->template_override,
            $content->translation->title,
            $body,
            self::entrySeo($content->translation) + ['home' => $path === '/'] + $this->pageTitleSeo($entry, $content->translation, $locale, $extra),
            $categoryTemplate,
            $extra,
        );

        return response($html);
    }

    /**
     * The name a page is searched under when another page or category of the site has the same name:
     * its parent page's name is added. A page without a staff-written Google title, with a published
     * parent, is the only one checked (one read); a post, the home page and a top-level page cost nothing.
     *
     * @param  array<string, mixed>  $extra
     * @return array{pageTitle?: string}
     */
    private function pageTitleSeo(Entry $entry, EntryTranslation $translation, string $locale, array $extra): array
    {
        $crumbs = is_array($extra['breadcrumb'] ?? null) ? array_values($extra['breadcrumb']) : [];
        if ($entry->type !== 'page' || trim((string) $translation->seo_title) !== '' || count($crumbs) < 3) {
            return [];
        }
        $crumb = $crumbs[count($crumbs) - 2];
        $parent = is_array($crumb) ? ($crumb['name'] ?? null) : null;

        return ['pageTitle' => $this->titles->title($translation->title, is_string($parent) ? $parent : null, $locale, $entry->id, null)];
    }

    /**
     * What a post or page tells search engines and link previews: its own Google title, else what
     * the site's search settings make of the page title (PageRenderer); its own Google description,
     * else its short excerpt, else nothing, so the head falls back to the site's default description
     * and then the brand description. A blank value counts as none.
     *
     * @return array{title: string|null, description: string|null}
     */
    private static function entrySeo(EntryTranslation $translation): array
    {
        $filled = static fn (?string $value): ?string => $value !== null && trim($value) !== '' ? $value : null;

        return [
            'title' => $filled($translation->seo_title),
            // The excerpt may hold line breaks (a news card shows it as is); a description is one line.
            'description' => $filled($translation->seo_description) ?? $filled(str((string) $translation->excerpt)->squish()->toString()),
        ];
    }
}
