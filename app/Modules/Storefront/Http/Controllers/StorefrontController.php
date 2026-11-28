<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductIndexLink;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RedirectRules;
use App\Modules\Cms\Routing\ResolvedCategory;
use App\Modules\Cms\Routing\ResolvedContent;
use App\Modules\Cms\Routing\ResolvedProduct;
use App\Modules\Cms\Routing\RouteResolver;
use App\Modules\Cms\Support\EntryCover;
use App\Modules\Cms\Support\ReusableBlockExpander;
use App\Modules\Cms\Taxonomy\CategoryTemplates;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Storefront\EdgeCache\PageEndTime;
use App\Modules\Storefront\Rendering\EntryPage;
use App\Modules\Storefront\Rendering\EntryPreviewPage;
use App\Modules\Storefront\Support\Breadcrumbs;
use App\Modules\Storefront\Support\ListPage;
use App\Modules\Storefront\Support\NotFoundPage;
use App\Modules\Storefront\Support\PageChrome;
use App\Modules\Storefront\Support\ProductPage;
use App\Modules\Storefront\Support\SlotContext;
use App\Modules\Storefront\Support\TitleDisambiguator;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Rendering\PageRenderer;
use App\Modules\Theme\Rendering\TemplateCatalog;
use App\Modules\Theme\Rendering\TemplateResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
    public function __construct(
        private RouteResolver $resolver,
        private PageRenderer $renderer,
        private SiteLocale $locale,
        private PathBuilder $paths,
        private MediaUrlResolver $media,
        private ListPage $lists,
        private TemplateResolver $templates,
        private Breadcrumbs $crumbs,
        private CategoryTemplates $categoryTemplates,
        private SlotContext $slots,
        private PageChrome $pageChrome,
        private ProductPage $productPage,
        private TitleDisambiguator $titles,
        private NotFoundPage $notFoundPage,
        private RedirectRules $redirectRules,
        private EntryPage $entryPage,
        private EntryPreviewPage $previewPage,
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
            $followed = $this->follow($this->resolver->redirect($locale, $path), $locale);
            if ($followed !== null) {
                return $followed;
            }

            $moved = $locale === $default ? $this->resolver->heldElsewhere($this->formerLocales($default), $path, routes: false) : null;
            abort_if($moved === null, 404);

            return redirect()->to($this->paths->publicUrl($path, $moved), 301);
        }

        $resolved = $this->resolver->resolve($locale, $path);

        if ($resolved === null) {
            $followed = $this->follow($this->resolver->redirect($locale, $path), $locale);
            if ($followed !== null) {
                return $followed;
            }

            // A clean path the default language does not hold may be an address of a language that
            // was the default before (a change of default moves its addresses under /{locale}).
            $moved = $locale === $default ? $this->resolver->heldElsewhere($this->formerLocales($default), $path, productVisible: fn (ResolvedProduct $product, string $in): bool => $this->productPage->published($product->productId, $in)?->translationFor($in) !== null) : null;
            if ($moved !== null) {
                return redirect()->to($this->paths->publicUrl($path, $moved), 301);
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
                : $this->entryPage->render($resolved, $locale, $path, $chrome);
        } catch (NotFoundHttpException) {
            return $this->notFound($chrome);
        }
    }

    /**
     * The admin's preview of a page or post (admin.entries.preview): the real storefront page of an
     * entry of the current site, whatever its status, drawn exactly as a published one would be, for
     * a member who may open it (EntryPolicy::preview). It lives under the admin's protected routes
     * (signed in, a member of this site) and is addressed by the entry's ulid: a draft has no public
     * address yet. An entry the member may not preview, one of another site (RLS), one in the Trash, a
     * language the site does not have or one the entry lacks all answer the same 404. Like the product preview
     * (ProductPreviewController): never cached or indexed, no referrer, the storefront's own
     * Content-Security-Policy, framed only by the admin, no view counted and its forms switched off.
     */
    public function preview(Request $request, string $ulid): Response
    {
        $default = $this->locale->default();
        $locale = $request->query('locale', $default);
        abort_unless(is_string($locale) && $this->locale->isEnabled($locale), 404);

        $entry = Entry::query()->where('ulid', $ulid)->addSelect([EntryCover::KEY => EntryCover::keySubquery()])->first();
        // not allowed answers like a missing entry (as the product preview does): a ulid probe learns nothing
        abort_unless($entry !== null && Gate::allows('preview', $entry), 404);
        $translation = $entry->translationFor($locale);
        abort_if($translation === null, 404);

        return $this->previewPage->respond($request, $entry, $translation, $locale);
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

    /** @param array<string, mixed> $chrome */
    private function notFound(array $chrome): Response
    {
        return $this->notFoundPage->respond($chrome);
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
     * The response for a stored redirect, or null when there is none or it is not followed. A path
     * on this site gets the language prefix laid over it (rows keep the unprefixed path of their
     * language, like routes). Another website's address is followed only while the website's switch
     * is on, and only as stored — exactly the form SafeLink::externalHttps() gives. Anything else
     * (`//host`, `/\\host`, a row under a sign-in or /admin address, an outside address with the
     * switch off) is ignored: the request goes on as if there were no row.
     */
    private function follow(?Redirect $redirect, string $locale): ?Response
    {
        if (! $redirect instanceof Redirect || ! $this->redirectRules->follows($redirect)) {
            return null;
        }
        if (SafeLink::isSitePath($redirect->to_path)) {
            return redirect()->to($this->paths->publicUrl($redirect->to_path, $locale), $redirect->status_code);
        }

        return redirect()->away($redirect->to_path, $redirect->status_code);
    }

    /**
     * The public languages that were the default before, the most recent first: where a clean path
     * that missed in the default language may still live (a change of default moved it under
     * /{locale}). Never any other language — a clean path is not a way into every translation.
     *
     * @return list<string>
     */
    private function formerLocales(string $default): array
    {
        return array_values(array_filter($this->locale->formerDefaults(), static fn (string $code): bool => $code !== $default));
    }

    /**
     * A path segment shaped like a BCP-47 langcode — `en`, `vi`, `pt-br` (the path is
     * already lower-cased). Broad SHAPE match only; isPublic() decides whether it is a real
     * locale visitors may open. The 2-letter base excludes ordinary content slugs (e.g. `tin-tuc`).
     */
    private function looksLikeLocale(string $segment): bool
    {
        return SiteLocale::looksLikeCode($segment);
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
        if ($taxonomy->vocabulary === Taxonomy::TAG) {
            return $this->renderTag($resolved, $locale, $path, $chrome);
        }
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
                // the cover's file named by id, in this query (EntryCover)
                ->addSelect([EntryCover::KEY => EntryCover::keySubquery()])
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
     * A tag's page: the published posts filed under the tag (a facet term, any pivot position from
     * Entry::FACET_POSITION_BASE up) whose version in this language is published, newest first, as
     * news cards with keyset paging like every list. Its title is "Tag: name" (the h1, the crumb and
     * the head's title), under a fixed sentence naming the site, on the plain dark band with its
     * pattern (a tag has no cover). While the tag has fewer than two such posts the page is
     * `noindex,follow` (a thin page; the sitemap leaves it out too, SitemapRoutes): the first page
     * holds every post then, so it decides without a count.
     *
     * A tag whose posts are all unpublished (a draft's, one waiting for approval, one in the trash)
     * has no page (404): a tag is made by any save of a member who may edit a post, so its name reaches
     * visitors only once a post carrying it went live, through publishing and so through approval
     * where a workflow asks for one. A tag no post carries any more (taken off its last post) answers
     * 404 too, so a name typed on a draft and removed again never becomes public. So does a page past
     * the last post.
     *
     * @param  array<string, mixed>  $chrome  page chrome (menu, lang, hreflang)
     */
    private function renderTag(ResolvedCategory $resolved, string $locale, string $path, array $chrome): Response
    {
        $position = $this->lists->position(request());
        // resolved per request: the controller is kept between requests, the current site is not
        $siteId = app(CurrentSite::class)->id();
        $tagged = fn (Builder $query): Builder => $query
            ->join('entry_taxonomies as tagged', fn (JoinClause $join) => $join->on('tagged.entry_id', '=', 'entries.id')
                ->where('tagged.site_id', $siteId)
                ->where('tagged.taxonomy_id', $resolved->taxonomy->id)->where('tagged.position', '>=', Entry::FACET_POSITION_BASE));
        $live = $tagged(Entry::query())
            ->where('entries.type', 'post')
            ->where('entries.status', 'published')
            ->whereHas('translations', fn (Builder $query) => $query->where('locale', $locale)->where('status', 'published'));
        ['rows' => $rows, 'more' => $more] = $this->lists->window($live->select('entries.*')->addSelect([EntryCover::KEY => EntryCover::keySubquery()])->with(['translations', 'routes']), Entry::class, $position);
        $first = $position['after'] === null && $position['page'] === 1;
        $site = trim(Cast::string(data_get($chrome, 'brand.name')));

        $extra = $chrome;
        $extra['chips'] = [];
        $extra['band'] = [
            'subtitle' => $site !== '' ? __('storefront.tag_intro', ['site' => $site]) : __('storefront.tag_intro_plain'),
            'image' => null,
            'srcset' => null,
            'pattern' => true,
        ];
        // no live post in this language carries the tag (or the page is past the last post): no page
        abort_if($rows->isEmpty(), 404);
        $extra['cards'] = $this->lists->postCards($rows, $locale, [], true);
        if ($first && ! $more && $rows->count() < 2) {
            $extra['robots'] = 'noindex,follow';
        }
        $title = __('storefront.tag_title', ['name' => $resolved->translation->name]);

        return $this->listResponse($resolved, $locale, $path, $position, $more, ListPage::ulidOf($rows->last()), [], $extra, 'grid-news', $title);
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
    private function listResponse(ResolvedCategory $resolved, string $locale, string $path, array $position, bool $more, ?string $lastUlid, array $titles, array $extra, ?string $listTemplate, ?string $title = null): Response
    {
        // the page's title (h1, last crumb, head): the category's name unless the caller words it
        $title ??= $resolved->translation->name;
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
        // a tag stands alone under Home, at its own address
        $trail = $resolved->taxonomy->vocabulary === Taxonomy::TAG
            ? [$this->crumbs->crumb($title, $this->paths->publicUrl($this->paths->forTag($resolved->translation->slug), $locale))]
            : $this->crumbs->category($resolved->taxonomy, $locale, $resolved->translation->name, $resolved->translation->slug);
        $parentName = count($trail) >= 2 ? $trail[count($trail) - 2]['name'] : null;
        $lastLevel = count($trail) - 1;
        $trail = array_map(
            fn (array $crumb, int $i): array => $i === $lastLevel ? ['name' => $crumb['name'], 'url' => $canonical, 'href' => $crumb['href']] : $crumb,
            $trail,
            array_keys($trail),
        );
        $extra['breadcrumb'] = [$this->crumbs->home($locale), ...$trail];
        $extra['crumbsShown'] = $this->crumbs->visible($extra['breadcrumb'], false);
        // The band below the list (the list's own category speaks first, then the site's default).
        // The shared blocks they place are put in first (one query for the page, none without any).
        $extra['slotBlocks'] = app(ReusableBlockExpander::class)->expand([], $this->slots->blocks(SlotContext::LIST_SLOTS, $locale, $resolved->taxonomy), $locale)['slots'];
        $extra['slotContext'] = $this->slots->context($extra['slotBlocks'], $resolved->taxonomy, $locale, is_array($extra['brand'] ?? null) ? $extra['brand'] : null);

        $links = $this->lists->links(request()->url(), $position, $more, $lastUlid);
        // a tag's page from the second on also leads back to the first, beside "previous"
        if ($resolved->taxonomy->vocabulary === Taxonomy::TAG && $position['after'] === null && $position['page'] > 1) {
            $links['firstUrl'] = request()->url();
        }

        $html = $this->renderer->renderCategory(
            $listTemplate,
            $title,
            $titles,
            $links,
            self::categorySeo($resolved->translation, $parentName, Cast::string(data_get($extra, 'brand.name'))) + $this->categoryTitleSeo($resolved, $locale, $parentName),
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
        app(PageEndTime::class)->until($product->expires_at);

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
     * The same for a category: its parent's name is added when the name is taken. The trail is the one
     * the page already built (home, the categories above, this one), so the parent's name costs nothing.
     *
     * @return array{pageTitle?: string}
     */
    private function categoryTitleSeo(ResolvedCategory $resolved, string $locale, ?string $parentName): array
    {
        $translation = $resolved->translation;
        if (trim((string) $translation->seo_title) !== '' || $parentName === null) {
            return [];
        }

        return ['pageTitle' => $this->titles->title($translation->name, $parentName, $locale, null, $resolved->taxonomy->id)];
    }

    /** A description for a search result: at most 160 characters, cut after a whole word. */
    private static function cut(string $text): string
    {
        if (mb_strlen($text) <= 160) {
            return $text;
        }
        $head = mb_substr($text, 0, 160);
        if (! preg_match('/\s/u', mb_substr($text, 160, 1))) {
            $space = mb_strrpos($head, ' ');
            if ($space !== false && $space > 0) {
                $head = mb_substr($head, 0, $space);
            }
        }

        return rtrim($head, " \t\n\r,;:.-");
    }

    /**
     * What a category tells search engines: its own Google title (else its name, through the site's
     * title template) and its own Google description, else its description, else a sentence made from
     * its name, the category above it and the site's name, so no two lists share one fallback sentence.
     * Without a site name the sentence is left out and the head falls back to the site's defaults.
     *
     * @return array{title: string|null, description: string|null}
     */
    private static function categorySeo(TaxonomyTranslation $translation, ?string $parent, string $site): array
    {
        $filled = static fn (?string $value): ?string => $value !== null && trim($value) !== '' ? $value : null;
        $site = trim($site);
        $generated = null;
        if ($site !== '') {
            $generated = $parent !== null && trim($parent) !== ''
                ? __('storefront.seo_list_description_child', ['name' => $translation->name, 'parent' => trim($parent), 'site' => $site])
                : __('storefront.seo_list_description', ['name' => $translation->name, 'site' => $site]);
        }

        return [
            'title' => $filled($translation->seo_title),
            'description' => $filled($translation->seo_description)
                ?? $filled(self::cut(str((string) $translation->description)->squish()->toString()))
                ?? (is_string($generated) ? $generated : null),
        ];
    }
}
