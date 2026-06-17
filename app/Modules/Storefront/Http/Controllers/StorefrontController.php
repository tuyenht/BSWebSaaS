<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Navigation\MenuResolver;
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
    ) {}

    public function __invoke(Request $request): Response
    {
        $path = strtolower('/'.ltrim($request->path(), '/'));
        $locale = $this->locale->default();

        $resolved = $this->resolver->resolve($locale, $path);

        if ($resolved === null) {
            $redirect = $this->resolver->redirect($locale, $path);
            abort_if($redirect === null, 404);

            return redirect()->to($redirect->to_path, $redirect->status_code);
        }

        // Header navigation for the active locale (single-locale storefront → locale is the
        // site default; the resolver's per-locale + default-locale fallback is forward-ready).
        $menu = $this->nav->resolve('primary', $locale, $locale, $path);

        return $resolved instanceof ResolvedCategory
            ? $this->renderCategory($resolved, $locale, $menu)
            : $this->renderEntry($resolved, $locale, $menu);
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
                    'url' => '/'.trim($categoryTranslation->slug, '/'),
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
                'url' => '/'.$categorySlug.'/'.trim($translation->slug, '/'),
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
