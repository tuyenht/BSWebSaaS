<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ContactPageSetting;
use App\Modules\Catalog\Support\ProductIndexLink;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\ResolvedCategory;
use App\Modules\Cms\Routing\ResolvedContent;
use App\Modules\Cms\Routing\ResolvedProduct;
use App\Modules\Cms\Routing\RouteResolver;
use App\Modules\Cms\Support\EntryViewCounter;
use App\Modules\Cms\Support\JobFacts;
use App\Modules\Cms\Taxonomy\CategoryTemplates;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Storefront\Support\ArticleData;
use App\Modules\Storefront\Support\Breadcrumbs;
use App\Modules\Storefront\Support\JobPosting;
use App\Modules\Storefront\Support\ListPage;
use App\Modules\Storefront\Support\PageChrome;
use App\Modules\Storefront\Support\PostDetail;
use App\Modules\Storefront\Support\ProductDetail;
use App\Modules\Storefront\Support\ProductPage;
use App\Modules\Storefront\Support\SlotContext;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Rendering\PageRenderer;
use App\Modules\Theme\Rendering\TemplateCatalog;
use App\Modules\Theme\Rendering\TemplateResolver;
use App\Modules\Theme\Theming\StorefrontSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public storefront: resolve (locale, path) → published content within the current site
 * (RLS-isolated), render it to SSR HTML through the theme. A path resolves to an entry
 * (page/post) or a category-list page; a miss falls back to a 301 (slug history) and
 * otherwise 404s. The inbound path is lower-cased to match the all-lowercase slug/route
 * invariant. Runs inside the tenant context established by SetCurrentSite.
 */
final readonly class StorefrontController
{
    /** The most links the 404 page suggests from the main menu. */
    private const int SUGGESTIONS_MAX = 6;

    public function __construct(
        private RouteResolver $resolver,
        private PageRenderer $renderer,
        private SiteLocale $locale,
        private PathBuilder $paths,
        private MediaUrlResolver $media,
        private EntryViewCounter $views,
        private StorefrontSettings $settings,
        private ListPage $lists,
        private TemplateResolver $templates,
        private Breadcrumbs $crumbs,
        private PostDetail $posts,
        private ProductDetail $products,
        private CategoryTemplates $categoryTemplates,
        private JobPosting $jobs,
        private SlotContext $slots,
        private PageChrome $pageChrome,
        private ProductPage $productPage,
    ) {}

    public function __invoke(Request $request): Response
    {
        $requestPath = strtolower('/'.ltrim($request->path(), '/'));

        // A path with a dot in it (`/wp-login.php`, `/.env`, `/.git/config`, also `/wp-login%2ephp`)
        // is never a page of the site: every slug is lower-case words joined by hyphens, and the
        // sitemap and robots files have routes of their own. These are scanners, so the only thing
        // looked up for them is a stored redirect (checked with the same call as any other path);
        // without one they get the plain 404 before any content, settings or menus are read.
        $dotted = str_contains(rawurldecode($requestPath), '.');

        $default = $this->locale->default();

        [$locale, $path, $redirectTo] = $this->localeContext($requestPath, $default);

        if ($redirectTo !== null) {
            // A default-locale prefix before a dotted path (`/vi//evil.example/x`) is refused, never rewritten.
            abort_if($dotted, 404);

            return redirect()->to($redirectTo, 301);
        }

        // Render the page chrome (form block submit button, validation hints, 404) in the resolved
        // content locale — storefront routes carry no SetLocale, and app.locale defaults to 'en', so
        // without this a vi site would show English UI strings.
        app()->setLocale($locale);

        if ($dotted) {
            $redirect = $this->resolver->redirect($locale, $path);
            abort_unless($redirect instanceof Redirect && SafeLink::isSitePath($redirect->to_path), 404);

            return redirect()->to($redirect->to_path, $redirect->status_code);
        }

        $resolved = $this->resolver->resolve($locale, $path);

        if ($resolved === null) {
            $redirect = $this->resolver->redirect($locale, $path);
            // A stored target that is not a path on this site (`//host`, `/\host`, a full link) is
            // never followed: a redirect row must not become a way off the site.
            if ($redirect instanceof Redirect && SafeLink::isSitePath($redirect->to_path)) {
                return redirect()->to($redirect->to_path, $redirect->status_code);
            }

            return $this->notFound($this->chrome($locale, $default, $requestPath, null, $path));
        }

        $chrome = $this->chrome($locale, $default, $requestPath, $resolved, $path);

        // Every "not here" past this point — an unpublished product, a product without this
        // language, a list page number or anchor that does not exist — answers with the same 404
        // page in the site's own header and footer. Only the 404 is caught: a 403 or 419 stays itself.
        try {
            if ($resolved instanceof ResolvedProduct) {
                return $this->renderProduct($resolved, $locale, $path, $chrome);
            }

            return $resolved instanceof ResolvedCategory
                ? $this->renderCategory($resolved, $locale, $path, $chrome)
                : $this->renderEntry($resolved, $locale, $path, $chrome);
        } catch (NotFoundHttpException) {
            return $this->notFound($chrome);
        }
    }

    /**
     * The page chrome for the active locale (PageChrome: header, footer, menus, business identity),
     * with the hreflang alternates of the resolved content (none for a page that was not found).
     *
     * @return array<string, mixed>
     */
    private function chrome(string $locale, string $default, string $requestPath, ResolvedContent|ResolvedCategory|ResolvedProduct|null $resolved, string $path): array
    {
        return $this->pageChrome->for($locale, $default, $requestPath, $resolved === null ? [] : $this->hreflangFor($resolved, $locale, $default, $path));
    }

    /**
     * The site's 404 page: its own header, footer and menus around the "not here" message, a way
     * home, a way to the site's contact page (when the site names one) and the first links of its
     * main menu. A real 404 status (never a page that answers 200) and kept out of search engines;
     * no canonical, share tags or alternates, since there is no page to point at.
     *
     * @param  array<string, mixed>  $chrome
     */
    private function notFound(array $chrome): Response
    {
        $locale = is_string($chrome['lang'] ?? null) ? $chrome['lang'] : $this->locale->default();
        $extra = $chrome;
        $extra['hreflang'] = [];
        $extra['robots'] = 'noindex';
        $extra['contactUrl'] = $this->products->quoteLink($locale, ContactPageSetting::ulid($this->settings->get(ContactPageSetting::GROUP, ContactPageSetting::KEY)));
        $extra['suggestions'] = self::suggestions(
            is_array($chrome['menu'] ?? null) ? $chrome['menu'] : [],
            is_string($chrome['homeUrl'] ?? null) ? $chrome['homeUrl'] : '/',
        );

        return response($this->renderer->renderNotFound(__('storefront.not_found.title'), $extra), 404);
    }

    /**
     * The 404 page's "you may be looking for" links: the top level of the main menu already loaded
     * for the header (no query), keeping only the links to a page of this site and leaving out the
     * home page (the page has its own button for it), at most six. Empty when the menu is.
     *
     * @param  list<mixed>|array<mixed>  $menu  the resolved primary menu tree
     * @return list<array{label: string, url: string}>
     */
    private static function suggestions(array $menu, string $homeUrl): array
    {
        $links = [];
        foreach ($menu as $item) {
            $url = is_array($item) ? ($item['url'] ?? null) : null;
            $label = is_array($item) ? ($item['label'] ?? null) : null;
            if (! is_string($url) || ! is_string($label) || trim($label) === '' || $url === $homeUrl || ! SafeLink::isSitePath($url)) {
                continue;
            }
            $links[] = ['label' => $label, 'url' => $url];
            if (count($links) === self::SUGGESTIONS_MAX) {
                break;
            }
        }

        return $links;
    }

    /**
     * Resolve the request's locale + UNPREFIXED content path from the URL (design.md §10/ADR-11).
     * The site's current default locale is unprefixed, so a /{default}/… request is a canonical
     * violation → 301 to the clean path. A langcode-shaped first segment that is a PUBLIC
     * non-default locale selects that locale (the remainder is its content path). Anything else
     * — no prefix, or a langcode-shaped segment that is not a public locale — resolves as a
     * default-locale path, so an unknown, disabled or not-yet-public locale prefix simply 404s on
     * the route miss (no soft-fallback to the default).
     *
     * @return array{0: string, 1: string, 2: string|null} [locale, contentPath, redirectTo]
     */
    private function localeContext(string $path, string $default): array
    {
        $segments = explode('/', trim($path, '/'));
        $first = $segments[0];

        if ($first === '' || ! $this->looksLikeLocale($first)) {
            return [$default, $path, null];
        }

        if ($first === $default) {
            // /{default}/x → /x ; /{default} → /. Empty segments are dropped: /{default}//host
            // must never become a protocol-relative `//host` redirect off the site.
            $rest = '/'.implode('/', array_filter(array_slice($segments, 1), fn (string $segment): bool => $segment !== ''));

            return [$default, $path, $rest];
        }

        if ($this->locale->isPublic($first)) {
            return [$first, '/'.implode('/', array_slice($segments, 1)), null];
        }

        return [$default, $path, null];   // langcode-shaped but not public → 404 via route miss
    }

    /**
     * A path segment shaped like a BCP-47 langcode — `en`, `vi`, `pt-br` (the path is
     * already lower-cased). Broad SHAPE match only; isPublic() decides whether it is a real
     * locale visitors may open. The 2-letter base excludes ordinary content slugs (e.g. `tin-tuc`).
     */
    private function looksLikeLocale(string $segment): bool
    {
        return preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $segment) === 1;
    }

    /**
     * @param  array<string, mixed>  $chrome  page chrome (menu, lang, hreflang)
     */
    private function renderEntry(ResolvedContent $content, string $locale, string $path, array $chrome): Response
    {
        $entry = $content->entry;

        // Buffer this page view in Valkey — fail-open, no Postgres write on a GET (§3.1).
        $this->views->record($entry->site_id, $entry->id);

        $categoryTemplate = null;
        $extra = $chrome;
        $canonical = url($this->paths->publicUrl($path, $locale));
        $extra['canonical'] = $canonical;
        $extra['ogType'] = $entry->type === 'post' ? 'article' : 'website';

        // Featured image for og:image — posts carry meta['image_url'] as a raw R2 key.
        // Resolve at 1280 (whitelisted width; Cloudflare Worker serves it via cf.image).
        // Pages without a featured image omit $extra['ogImage'] so head.blade.php
        // falls back to the site-wide brand og_image automatically.
        $metaImageKey = data_get($entry->meta, 'image_url');
        if (is_string($metaImageKey) && $metaImageKey !== '') {
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
                );
            }

            // The blocks of the side column and of the band below the post; the newest posts of the
            // category's root branch are read with the related posts only when a block asks for them.
            $slotBlocks = $this->slots->blocks(SlotContext::POST_SLOTS, $locale, $category);
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
                // The capability layout draws neither neighbours nor related cards: read them only when a block needs the recent posts.
                if ($postTemplate !== 'capability' || $withRecent) {
                    $extra += $this->posts->siblings($entry, $category, $locale, true, $withRecent);
                }
            }
            $extra['slotContext'] = $this->slots->context($slotBlocks, $category, $locale, $brandData, is_array($extra['recent'] ?? null) ? $extra['recent'] : [], $entry->id);
            unset($extra['recent']);
        } elseif ($path !== '/') {
            $extra['breadcrumb'] = [$this->crumbs->home($locale), ...$this->crumbs->parentPages($entry, $locale), $self];
        }

        // A page on the layout with a table of contents shows the day it took effect (and the day it
        // last changed) in its title band.
        if ($entry->type === 'page' && $entry->template_override === TemplateCatalog::PAGE_TOC) {
            $extra['pageDates'] = $this->posts->dates($entry, $content->translation, $locale);
        }

        // The home page names the website itself: the name search results show for the site.
        $brandName = data_get($chrome, 'brand.name');
        if ($path === '/' && is_string($brandName) && trim($brandName) !== '') {
            $extra['websiteLd'] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => trim($brandName), 'url' => $canonical, 'inLanguage' => $locale];
        }

        $html = $this->renderer->render(
            $entry->type,
            $entry->template_override,
            $content->translation->title,
            $content->translation->body,
            self::entrySeo($content->translation) + ['home' => $path === '/'],
            $categoryTemplate,
            $extra,
        );

        return response($html);
    }

    /**
     * A category list page. A category with a card layout (`grid-*` list template) lists its whole
     * subtree as cards with child-category chips; the default layout lists only its own posts as
     * titles. Both page without a COUNT (ListPage).
     *
     * @param  array<string, mixed>  $chrome  page chrome (menu, lang, hreflang)
     */
    private function renderCategory(ResolvedCategory $resolved, string $locale, string $path, array $chrome): Response
    {
        // When this category IS the catalog section's bound taxonomy AND real products exist, it is
        // the product index — list published products instead of entries (the storefront may depend
        // on Catalog). With no products yet it falls through to the entry list, so the page keeps
        // working through the post→product migration (posts until products land, products after).
        // A category below the catalog root lists the products filed under it, on the same terms.
        $taxonomy = $resolved->taxonomy;
        // The real list layout, with "follow the parent" already turned into a layout name or null.
        $listTemplate = $this->categoryTemplates->list($taxonomy);
        // Asked through ProductIndexLink, which remembers both for the request: a page showing the
        // "Mua hàng" button has already read them.
        $shop = app(ProductIndexLink::class);
        $catalogRoot = $shop->catalogRoot();
        $hasProducts = fn (): bool => $shop->hasPublishedProducts();
        if ($catalogRoot === $taxonomy->id && $hasProducts()) {
            return $this->renderProductIndex($resolved, $locale, $path, $chrome, false, $listTemplate);
        }
        if ($catalogRoot !== null && $this->lists->isUnder($taxonomy, $catalogRoot) && $hasProducts()) {
            return $this->renderProductIndex($resolved, $locale, $path, $chrome, true, $listTemplate);
        }

        $position = $this->lists->position(request());
        $cards = $this->templates->listShape($listTemplate) === 'cards';

        if ($cards) {
            // Posts whose PRIMARY category (pivot position 0) is this one or any below it.
            $query = Entry::query()
                ->select('entries.*', 'entry_taxonomies.taxonomy_id as primary_taxonomy_id')
                ->join('entry_taxonomies', fn (JoinClause $join) => $join->on('entry_taxonomies.entry_id', '=', 'entries.id')->where('entry_taxonomies.position', 0))
                ->whereIn('entry_taxonomies.taxonomy_id', $this->lists->subtree($taxonomy));
        } else {
            // Posts whose PRIMARY category (pivot position 0) is this one.
            $query = $taxonomy->entries()->where('entry_taxonomies.position', 0);
        }
        $query->where('entries.type', 'post')
            ->where('entries.status', 'published')
            ->whereHas('translations', fn (Builder $query) => $query->where('locale', $locale)->where('status', 'published'))
            ->with(['translations', 'routes']);
        ['rows' => $rows, 'more' => $more] = $this->lists->window($query, Entry::class, $position);

        $posts = [];
        $extra = $chrome;
        if ($cards) {
            ['chips' => $chips, 'labels' => $labels] = $this->lists->chips($taxonomy, $locale);
            $extra['cards'] = $this->lists->postCards($rows, $locale, $labels, TemplateCatalog::cards($listTemplate) === 'news');
            $extra['chips'] = $chips;
            $extra['band'] = $this->listBand($resolved);
        } else {
            foreach ($rows as $post) {
                $translation = $post->translationFor($locale);
                if ($translation === null) {
                    continue;
                }
                // Link each card to the post's STORED route, so it follows the section's permalink
                // preset (flat, category, …) exactly instead of a hardcoded shape. A published post
                // always has a route; fall back to a config-correct compose if one is somehow absent.
                $route = $post->routes->firstWhere('locale', $locale);
                $unprefixed = $route !== null
                    ? $route->path
                    : $this->paths->forEntry($post, $locale, trim((string) $translation->slug, '/'));
                $posts[] = [
                    'title' => $translation->title,
                    'url' => $this->paths->publicUrl($unprefixed, $locale),
                ];
            }
        }

        return $this->listResponse($resolved, $locale, $path, $position, $more, ListPage::ulidOf($rows->last()), $posts, $extra, $listTemplate);
    }

    /**
     * The catalog product index — the catalog section's category (e.g. /san-pham) lists published
     * products newest-first, each linking to its canonical product route; a category below it
     * lists the products filed under it (its own on the default layout, its whole subtree on a
     * card layout). The product query lives here so Cms and Theme stay Catalog-agnostic.
     *
     * @param  array<string, mixed>  $chrome  page chrome (menu, lang, hreflang)
     */
    private function renderProductIndex(ResolvedCategory $resolved, string $locale, string $path, array $chrome, bool $filtered, ?string $listTemplate): Response
    {
        $taxonomy = $resolved->taxonomy;
        $position = $this->lists->position(request());
        $cards = $this->templates->listShape($listTemplate) === 'cards';

        $query = Product::query()
            ->where('status', 'published')
            ->with($cards ? ['translations', 'taxonomies.translations', 'images.media'] : ['translations', 'taxonomies']);
        if ($filtered) {
            $ids = $cards ? $this->lists->subtree($taxonomy) : [$taxonomy->id];
            $query->whereExists(fn (QueryBuilder $filed) => $filed->selectRaw('1')
                ->from('product_taxonomies')
                ->whereColumn('product_taxonomies.product_id', 'products.id')
                ->whereIn('product_taxonomies.taxonomy_id', $ids));
        }
        ['rows' => $rows, 'more' => $more] = $this->lists->window($query, Product::class, $position);

        $products = [];
        $extra = $chrome;
        if ($cards) {
            $extra['cards'] = $this->lists->productCards($rows, $locale);
            $extra['chips'] = $this->lists->chips($taxonomy, $locale)['chips'];
            $extra['emptyKey'] = 'storefront.no_products';
            $extra['band'] = $this->listBand($resolved);
        } else {
            foreach ($rows as $product) {
                $translation = $product->translationFor($locale);
                if ($translation === null) {
                    continue;
                }
                $unprefixed = $this->paths->forProduct($product->primaryCategory(), (string) $translation->slug, $locale);
                $products[] = ['title' => $translation->name, 'url' => $this->paths->publicUrl($unprefixed, $locale)];
            }
        }

        return $this->listResponse($resolved, $locale, $path, $position, $more, ListPage::ulidOf($rows->last()), $products, $extra, $listTemplate);
    }

    /**
     * Render a list page: self canonical (with its page or anchor), breadcrumb, the paging links,
     * and `noindex,follow` on keyset pages (the sitemap lists every item).
     *
     * @param  array{page: int, after: string|null}  $position
     * @param  list<array{title: string, url: string}>  $titles
     * @param  array<string, mixed>  $extra
     */
    private function listResponse(ResolvedCategory $resolved, string $locale, string $path, array $position, bool $more, ?string $lastUlid, array $titles, array $extra, ?string $listTemplate): Response
    {
        // Each paginated page self-canonicals (with ?page=N) — rel=prev/next signals the series;
        // canonicaling page-N→page-1 would drop the deeper pages from the index.
        $base = url($this->paths->publicUrl($path, $locale));
        $canonical = $base;
        if ($position['after'] !== null) {
            $canonical .= '?sau='.$position['after'];
            $extra['robots'] = 'noindex,follow';
        } elseif ($position['page'] > 1) {
            $canonical .= '?page='.$position['page'];
        }
        $extra['canonical'] = $canonical;
        $extra['ogType'] = 'website';
        // Home → the categories above → this one (its own entry keeps the page's canonical address).
        $trail = $this->crumbs->category($resolved->taxonomy, $locale, $resolved->translation->name, $resolved->translation->slug);
        $trail[count($trail) - 1]['url'] = $canonical;
        $extra['breadcrumb'] = [$this->crumbs->home($locale), ...$trail];
        // The band below the list (the list's own category speaks first, then the site's default).
        $extra['slotBlocks'] = $this->slots->blocks(SlotContext::LIST_SLOTS, $locale, $resolved->taxonomy);
        $extra['slotContext'] = $this->slots->context($extra['slotBlocks'], $resolved->taxonomy, $locale, is_array($extra['brand'] ?? null) ? $extra['brand'] : null);

        $html = $this->renderer->renderCategory(
            $listTemplate,
            $resolved->translation->name,
            $titles,
            $this->lists->links(request()->url(), $position, $more, $lastUlid),
            [],   // a category has no Google title of its own: its name, through the site's title template
            $extra,
        );

        return response($html);
    }

    /**
     * Render a catalog product detail page (ProductPage). The product is loaded in the Storefront
     * (the storefront may depend on Catalog; Cms routing must not) and visibility is enforced at load
     * — an unpublished/soft-deleted product, or one with no translation for the locale, 404s.
     *
     * @param  array<string, mixed>  $chrome  page chrome (menu, lang, hreflang)
     */
    private function renderProduct(ResolvedProduct $resolved, string $locale, string $path, array $chrome): Response
    {
        $product = $this->productPage->published($resolved->productId, $locale);
        abort_if($product === null, 404);

        $translation = $product->translationFor($locale);
        abort_if($translation === null, 404);

        return response($this->productPage->render($product, $translation, $locale, $path, $chrome));
    }

    /**
     * hreflang alternates for the resolved content (design.md §10/§18): one entry per locale the
     * content is PUBLISHED in and visitors can open (self from the in-hand path; other locales from
     * their stored route), plus x-default → the site default. A translation in a language the site
     * has not made public yet is left out — its URL would 404. Every URL is absolute +
     * locale-prefixed via publicUrl.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    private function hreflangFor(ResolvedContent|ResolvedCategory|ResolvedProduct $resolved, string $locale, string $default, string $path): array
    {
        if ($resolved instanceof ResolvedProduct) {
            return [];   // product hreflang alternates are a later step (An Vinh products are single-locale)
        }

        $public = $this->locale->public();

        if ($resolved instanceof ResolvedContent) {
            $entry = $resolved->entry;
            $others = [];
            foreach ($entry->translations as $translation) {
                if ($translation->status === 'published' && $translation->locale !== $locale && in_array($translation->locale, $public, true)) {
                    $others[] = $translation->locale;
                }
            }
            $paths = $this->publishedPaths($locale, $path, 'entry_id', $entry->id, $others);
        } else {
            $taxonomy = $resolved->taxonomy;
            $others = [];
            foreach ($taxonomy->translations as $translation) {
                if ($translation->locale !== $locale && in_array($translation->locale, $public, true)) {
                    $others[] = $translation->locale;
                }
            }
            $paths = $this->publishedPaths($locale, $path, 'taxonomy_id', $taxonomy->id, $others);
        }

        $alternates = [];
        foreach ($paths as $altLocale => $altPath) {
            $alternates[] = ['hreflang' => $altLocale, 'href' => url($this->paths->publicUrl($altPath, $altLocale))];
        }
        if (isset($paths[$default])) {
            $alternates[] = ['hreflang' => 'x-default', 'href' => url($this->paths->publicUrl($paths[$default], $default))];
        }

        return $alternates;
    }

    /**
     * Map each available locale → its UNPREFIXED content path: the self locale from the in-hand
     * path (no query); other locales from one batched route lookup (so the hot single-locale path
     * adds no query). A locale with a translation but no route is omitted (unreachable).
     *
     * @param  list<string>  $otherLocales
     * @return array<string, string>
     */
    private function publishedPaths(string $selfLocale, string $selfPath, string $routeColumn, int $routableId, array $otherLocales): array
    {
        $paths = [$selfLocale => $selfPath];

        if ($otherLocales !== []) {
            $routes = SiteRoute::query()
                ->where($routeColumn, $routableId)
                ->whereIn('locale', $otherLocales)
                ->get(['locale', 'path']);

            foreach ($routes as $route) {
                $paths[$route->locale] = $route->path;
            }
        }

        return $paths;
    }

    /**
     * The band of a card list page: the category's description under its name and its cover photo
     * (one read, only when it has one) behind them, with a srcset for the page's main image. No
     * cover, or a cover that is gone, means the plain band.
     *
     * @return array{subtitle: string, image: string|null, srcset: string|null}
     */
    private function listBand(ResolvedCategory $resolved): array
    {
        $image = null;
        $srcset = null;
        $coverId = $resolved->taxonomy->cover_media_id;
        if ($coverId !== null) {
            $cover = Media::query()->whereKey($coverId)->first(['id', 'storage_key', 'width']);
            if ($cover !== null && $cover->storage_key !== '') {
                $image = $this->media->url($cover->storage_key, 1280);
                $srcset = $this->media->srcset($cover->storage_key, [640, 960, 1280, 1920], $cover->width);
            }
        }

        return [
            'subtitle' => trim((string) $resolved->translation->description),
            'image' => $image,
            'srcset' => $srcset,
        ];
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
