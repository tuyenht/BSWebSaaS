<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Navigation\MenuResolver;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\ResolvedCategory;
use App\Modules\Cms\Routing\ResolvedContent;
use App\Modules\Cms\Routing\RouteResolver;
use App\Modules\Tenancy\Context\SiteLocale;
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

        // Header navigation for the active locale; active-state matches the (prefixed) request
        // path. A missing per-locale assignment falls back to the default-locale menu.
        $menu = $this->nav->resolve('primary', $locale, $default, $requestPath);

        return $resolved instanceof ResolvedCategory
            ? $this->renderCategory($resolved, $locale, $menu)
            : $this->renderEntry($resolved, $locale, $menu);
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
     * A path segment shaped like a BCP-47 langcode — `en`, `vi`, `zh-hans`, `pt-br` (the path is
     * already lower-cased). Broad SHAPE match only; isEnabled() decides whether it is a real,
     * enabled locale. The 2-letter base excludes ordinary content slugs (e.g. `tin-tuc`).
     */
    private function looksLikeLocale(string $segment): bool
    {
        return preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $segment) === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $menu  resolved nav tree (header chrome)
     */
    private function renderEntry(ResolvedContent $content, string $locale, array $menu): Response
    {
        $entry = $content->entry;

        $categoryTemplate = null;
        $extra = [];

        // A post is shown under its primary category: the category drives its layout
        // (detail_template) and a breadcrumb back to the category list. Pages skip this.
        // The Cms lookup lives here so the Theme module stays Cms-agnostic.
        if ($entry->type === 'post') {
            $category = $entry->taxonomies()->first();
            $categoryTemplate = $category?->detail_template;

            $categoryTranslation = $category?->translationFor($locale);
            if ($categoryTranslation !== null) {
                $extra['category'] = [
                    'name' => $categoryTranslation->name,
                    'url' => $this->paths->publicUrl('/'.trim($categoryTranslation->slug, '/'), $locale),
                ];
            }
        }

        $extra['menu'] = $menu;

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
     * @param  list<array<string, mixed>>  $menu  resolved nav tree (header chrome)
     */
    private function renderCategory(ResolvedCategory $resolved, string $locale, array $menu): Response
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

        $html = $this->renderer->renderCategory(
            $resolved->taxonomy->list_template,
            $resolved->translation->name,
            $posts,
            [
                'prevUrl' => $paginator->previousPageUrl(),
                'nextUrl' => $paginator->nextPageUrl(),
            ],
            ['title' => $resolved->translation->name],
            ['menu' => $menu],
        );

        return response($html);
    }
}
