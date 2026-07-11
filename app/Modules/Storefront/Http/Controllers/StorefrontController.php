<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Navigation\MenuResolver;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\ResolvedCategory;
use App\Modules\Cms\Routing\ResolvedContent;
use App\Modules\Cms\Routing\ResolvedProduct;
use App\Modules\Cms\Routing\RouteResolver;
use App\Modules\Cms\Support\EntryViewCounter;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\PageRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public storefront: resolve (locale, path) → published content within the current site
 * (RLS-isolated), render it to SSR HTML through the theme. A path resolves to an entry
 * (page/post) or a category-list page; a miss falls back to a 301 (slug history) and
 * otherwise 404s. The inbound path is lower-cased to match the all-lowercase slug/route
 * invariant. Runs inside the tenant context established by SetCurrentSite.
 */
final readonly class StorefrontController
{
    /** Posts per category-list page. */
    private const int POSTS_PER_PAGE = 12;

    public function __construct(
        private RouteResolver $resolver,
        private PageRenderer $renderer,
        private SiteLocale $locale,
        private MenuResolver $nav,
        private PathBuilder $paths,
        private MediaUrlResolver $media,
        private EntryViewCounter $views,
    ) {}

    public function __invoke(Request $request): Response
    {
        $requestPath = strtolower('/'.ltrim($request->path(), '/'));
        $default = $this->locale->default();

        [$locale, $path, $redirectTo] = $this->localeContext($requestPath, $default);

        if ($redirectTo !== null) {
            return redirect()->to($redirectTo, 301);
        }

        // Render the page chrome (form block submit button, validation hints, 404) in the resolved
        // content locale — storefront routes carry no SetLocale, and app.locale defaults to 'en', so
        // without this a vi site would show English UI strings.
        app()->setLocale($locale);

        $resolved = $this->resolver->resolve($locale, $path);

        if ($resolved === null) {
            $redirect = $this->resolver->redirect($locale, $path);
            abort_if(! $redirect instanceof Redirect, 404);

            return redirect()->to($redirect->to_path, $redirect->status_code);
        }

        // Page chrome for the active locale: header nav (active-state matches the prefixed request
        // path; a missing per-locale assignment falls back to the default-locale menu), the
        // <html lang>, and the hreflang alternates.
        $chrome = [
            'menu' => $this->nav->resolve('primary', $locale, $default, $requestPath),
            'lang' => $locale,
            'hreflang' => $this->hreflangFor($resolved, $locale, $default, $path),
            'brand' => $this->brand(),
        ];

        if ($resolved instanceof ResolvedProduct) {
            return $this->renderProduct($resolved, $locale, $path, $chrome);
        }

        return $resolved instanceof ResolvedCategory
            ? $this->renderCategory($resolved, $locale, $path, $chrome)
            : $this->renderEntry($resolved, $locale, $path, $chrome);
    }

    /**
     * The tenant's storefront brand (name + logo URL) from the 'brand' site setting, or null when
     * unset — so the header shows a logo for a site that has one and just the nav for one that
     * doesn't. RLS-scoped to the active site.
     *
     * @return array<string, mixed>|null
     */
    private function brand(): ?array
    {
        $value = SiteSetting::query()->where('key', 'brand')->value('value');
        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Resolve the request's locale + UNPREFIXED content path from the URL (design.md §10/ADR-11).
     * The site's current default locale is unprefixed, so a /{default}/… request is a canonical
     * violation → 301 to the clean path. A langcode-shaped first segment that is an ENABLED
     * non-default locale selects that locale (the remainder is its content path). Anything else
     * — no prefix, or a langcode-shaped segment that is not an enabled locale — resolves as a
     * default-locale path, so an unknown/disabled locale prefix simply 404s on the route miss
     * (no soft-fallback to the default).
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
            $rest = '/'.implode('/', array_slice($segments, 1));   // /{default}/x → /x ; /{default} → /

            return [$default, $path, $rest];
        }

        if ($this->locale->isEnabled($first)) {
            return [$first, '/'.implode('/', array_slice($segments, 1)), null];
        }

        return [$default, $path, null];   // langcode-shaped but not enabled → 404 via route miss
    }

    /**
     * A path segment shaped like a BCP-47 langcode — `en`, `vi`, `pt-br` (the path is
     * already lower-cased). Broad SHAPE match only; isEnabled() decides whether it is a real,
     * enabled locale. The 2-letter base excludes ordinary content slugs (e.g. `tin-tuc`).
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

        // A post is shown under its primary category: the category drives its layout
        // (detail_template) and a breadcrumb back to the category list. Pages skip this.
        // The Cms lookup lives here so the Theme module stays Cms-agnostic.
        if ($entry->type === 'post') {
            $category = $entry->taxonomies()->first();
            $categoryTemplate = $category?->detail_template;

            $categoryTranslation = $category?->translationFor($locale);
            if ($categoryTranslation !== null) {
                $categoryUrl = $this->paths->publicUrl('/'.trim($categoryTranslation->slug, '/'), $locale);
                $extra['category'] = [
                    'name' => $categoryTranslation->name,
                    'url' => $categoryUrl,
                ];
                // BreadcrumbList trail (Home → category → self) for the shared head's JSON-LD;
                // mirrors the on-page post breadcrumb.
                $extra['breadcrumb'] = [
                    $this->homeCrumb($locale),
                    ['name' => $categoryTranslation->name, 'url' => url($categoryUrl)],
                    ['name' => $content->translation->title, 'url' => $canonical],
                ];
            }
        }

        $html = $this->renderer->render(
            $entry->type,
            $entry->template_override,
            $content->translation->title,
            $content->translation->body,
            [
                'title' => $content->translation->seo_title,
                'description' => $content->translation->seo_description,
            ],
            $categoryTemplate,
            $extra,
        );

        return response($html);
    }

    /**
     * @param  array<string, mixed>  $chrome  page chrome (menu, lang, hreflang)
     */
    private function renderCategory(ResolvedCategory $resolved, string $locale, string $path, array $chrome): Response
    {
        // When this category IS the catalog section's bound taxonomy AND real products exist, it is
        // the product index — list published products instead of entries (the storefront may depend
        // on Catalog). With no products yet it falls through to the entry list, so the page keeps
        // working through the post→product migration (posts until products land, products after).
        $catalogSection = ContentSection::query()->where('key', 'products')->first();
        if ($catalogSection !== null
            && $catalogSection->taxonomy_id === $resolved->taxonomy->id
            && Product::query()->where('status', 'published')->exists()) {
            return $this->renderProductIndex($resolved, $locale, $path, $chrome);
        }

        $categorySlug = trim($resolved->translation->slug, '/');

        // Posts whose PRIMARY category (pivot position 0) is this one — so each lands at
        // /{categorySlug}/{postSlug} (its canonical route). Only published, locale-present.
        $paginator = $resolved->taxonomy->entries()
            ->where('entries.type', 'post')
            ->where('entries.status', 'published')
            ->where('entry_taxonomies.position', 0)
            ->whereHas('translations', fn (Builder $query) => $query->where('locale', $locale)->where('status', 'published'))
            ->with('translations')
            ->orderByDesc('entries.published_at')
            ->paginate(self::POSTS_PER_PAGE);

        $posts = [];
        foreach ($paginator->items() as $post) {
            $translation = $post->translationFor($locale);
            if ($translation === null) {
                continue;
            }
            $posts[] = [
                'title' => $translation->title,
                'url' => $this->paths->publicUrl('/'.$categorySlug.'/'.trim((string) $translation->slug, '/'), $locale),
            ];
        }

        // Each paginated page self-canonicals (with ?page=N) — rel=prev/next signals the series;
        // canonicaling page-N→page-1 would drop the deeper pages from the index.
        $canonical = url($this->paths->publicUrl($path, $locale));
        if ($paginator->currentPage() > 1) {
            $canonical .= '?page='.$paginator->currentPage();
        }
        $extra = $chrome;
        $extra['canonical'] = $canonical;
        $extra['ogType'] = 'website';
        $extra['breadcrumb'] = [
            $this->homeCrumb($locale),
            ['name' => $resolved->translation->name, 'url' => $canonical],
        ];

        $html = $this->renderer->renderCategory(
            $resolved->taxonomy->list_template,
            $resolved->translation->name,
            $posts,
            [
                'prevUrl' => $paginator->previousPageUrl(),
                'nextUrl' => $paginator->nextPageUrl(),
            ],
            ['title' => $resolved->translation->name],
            $extra,
        );

        return response($html);
    }

    /**
     * The catalog product index — the catalog section's category (e.g. /san-pham) lists published
     * products newest-first, each linking to its canonical product route, on the category layout.
     * The product query lives here so Cms and Theme stay Catalog-agnostic.
     *
     * @param  array<string, mixed>  $chrome  page chrome (menu, lang, hreflang)
     */
    private function renderProductIndex(ResolvedCategory $resolved, string $locale, string $path, array $chrome): Response
    {
        $paginator = Product::query()
            ->where('status', 'published')
            ->with(['translations', 'taxonomies'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::POSTS_PER_PAGE);

        $products = [];
        foreach ($paginator->items() as $product) {
            $translation = $product->translationFor($locale);
            if ($translation === null) {
                continue;
            }
            $unprefixed = $this->paths->forProduct($product->taxonomies->first(), (string) $translation->slug, $locale);
            $products[] = ['title' => $translation->name, 'url' => $this->paths->publicUrl($unprefixed, $locale)];
        }

        $canonical = url($this->paths->publicUrl($path, $locale));
        if ($paginator->currentPage() > 1) {
            $canonical .= '?page='.$paginator->currentPage();
        }
        $extra = $chrome;
        $extra['canonical'] = $canonical;
        $extra['ogType'] = 'website';
        $extra['breadcrumb'] = [
            $this->homeCrumb($locale),
            ['name' => $resolved->translation->name, 'url' => $canonical],
        ];

        $html = $this->renderer->renderCategory(
            $resolved->taxonomy->list_template,
            $resolved->translation->name,
            $products,
            ['prevUrl' => $paginator->previousPageUrl(), 'nextUrl' => $paginator->nextPageUrl()],
            ['title' => $resolved->translation->name],
            $extra,
        );

        return response($html);
    }

    /**
     * Render a catalog product detail page. The product is loaded HERE (the storefront may depend on
     * Catalog; Cms routing must not) and visibility is enforced at load — an unpublished/soft-deleted
     * product, or one with no translation for the locale, 404s. Name + description HTML + gallery are
     * handed to the Theme as primitives.
     *
     * @param  array<string, mixed>  $chrome  page chrome (menu, lang, hreflang)
     */
    private function renderProduct(ResolvedProduct $resolved, string $locale, string $path, array $chrome): Response
    {
        $product = Product::query()
            ->whereKey($resolved->productId)
            ->where('status', 'published')
            ->with(['translations', 'images.media'])
            ->first();

        abort_if($product === null, 404);

        $translation = $product->translationFor($locale);
        abort_if($translation === null, 404);

        $canonical = url($this->paths->publicUrl($path, $locale));
        $extra = $chrome;
        $extra['canonical'] = $canonical;
        $extra['ogType'] = 'product';
        $extra['breadcrumb'] = [
            $this->homeCrumb($locale),
            ['name' => $translation->name, 'url' => $canonical],
        ];

        $images = [];
        foreach ($product->images as $image) {
            $key = $image->media?->storage_key;
            if (is_string($key) && $key !== '') {
                $images[] = ['url' => $this->media->url($key, 1280), 'alt' => $translation->name];
            }
        }
        if ($images !== []) {
            $extra['ogImage'] = $images[0]['url'];
        }

        // Product JSON-LD (brochure mode: no offers/price — this catalog sells nothing online).
        // Description must be plain text, so the sanitized HTML body is stripped; the dedicated
        // SEO description wins when the editor wrote one.
        $ldDescription = ! empty($translation->seo_description)
            ? $translation->seo_description
            : str((string) $translation->description)->stripTags()->squish()->limit(300)->toString();
        $extra['productLd'] = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $translation->name,
            'description' => $ldDescription !== '' ? $ldDescription : null,
            'image' => array_column($images, 'url') ?: null,
            'url' => $canonical,
            'brand' => ($brandName = data_get($chrome, 'brand.name')) !== null && $brandName !== ''
                ? ['@type' => 'Brand', 'name' => $brandName]
                : null,
        ], fn ($value) => $value !== null);

        $html = $this->renderer->renderProduct(
            $translation->name,
            (string) $translation->description,
            $images,
            ['title' => $translation->seo_title, 'description' => $translation->seo_description],
            $extra,
        );

        return response($html);
    }

    /**
     * hreflang alternates for the resolved content (design.md §10/§18): one entry per locale the
     * content is PUBLISHED in (self from the in-hand path; other locales from their stored route),
     * plus x-default → the site default. Every URL is absolute + locale-prefixed via publicUrl.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    private function hreflangFor(ResolvedContent|ResolvedCategory|ResolvedProduct $resolved, string $locale, string $default, string $path): array
    {
        if ($resolved instanceof ResolvedProduct) {
            return [];   // product hreflang alternates are a later step (An Vinh products are single-locale)
        }

        if ($resolved instanceof ResolvedContent) {
            $entry = $resolved->entry;
            $others = [];
            foreach ($entry->translations as $translation) {
                if ($translation->status === 'published' && $translation->locale !== $locale) {
                    $others[] = $translation->locale;
                }
            }
            $paths = $this->publishedPaths($locale, $path, 'entry_id', $entry->id, $others);
        } else {
            $taxonomy = $resolved->taxonomy;
            $others = [];
            foreach ($taxonomy->translations as $translation) {
                if ($translation->locale !== $locale) {
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
     * The Home crumb shared by every breadcrumb trail — an absolute, locale-correct URL.
     *
     * @return array{name: string, url: string}
     */
    private function homeCrumb(string $locale): array
    {
        return ['name' => 'Trang chủ', 'url' => url($this->paths->publicUrl('/', $locale))];
    }
}
