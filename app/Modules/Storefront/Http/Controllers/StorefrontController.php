<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Navigation\MenuResolver;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\ResolvedCategory;
use App\Modules\Cms\Routing\ResolvedContent;
use App\Modules\Cms\Routing\RouteResolver;
use App\Modules\Tenancy\Context\SiteLocale;
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
final class StorefrontController
{
    /** Posts per category-list page. */
    private const POSTS_PER_PAGE = 12;

    public function __construct(
        private readonly RouteResolver $resolver,
        private readonly PageRenderer $renderer,
        private readonly SiteLocale $locale,
        private readonly MenuResolver $nav,
        private readonly PathBuilder $paths,
    ) {}

    public function __invoke(Request $request): Response
    {
        $requestPath = strtolower('/'.ltrim($request->path(), '/'));
        $default = $this->locale->default();

        [$locale, $path, $redirectTo] = $this->localeContext($requestPath, $default);

        if ($redirectTo !== null) {
            return redirect()->to($redirectTo, 301);
        }

        $resolved = $this->resolver->resolve($locale, $path);

        if ($resolved === null) {
            $redirect = $this->resolver->redirect($locale, $path);
            abort_if($redirect === null, 404);

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

        $categoryTemplate = null;
        $extra = $chrome;
        $canonical = url($this->paths->publicUrl($path, $locale));
        $extra['canonical'] = $canonical;
        $extra['ogType'] = $entry->type === 'post' ? 'article' : 'website';

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
                'url' => $this->paths->publicUrl('/'.$categorySlug.'/'.trim($translation->slug, '/'), $locale),
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
     * hreflang alternates for the resolved content (design.md §10/§18): one entry per locale the
     * content is PUBLISHED in (self from the in-hand path; other locales from their stored route),
     * plus x-default → the site default. Every URL is absolute + locale-prefixed via publicUrl.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    private function hreflangFor(ResolvedContent|ResolvedCategory $resolved, string $locale, string $default, string $path): array
    {
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
