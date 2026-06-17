<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

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

        return $resolved instanceof ResolvedCategory
            ? $this->renderCategory($resolved, $locale)
            : $this->renderEntry($resolved);
    }

    private function renderEntry(ResolvedContent $content): Response
    {
        $entry = $content->entry;

        // A post's layout can be set per primary category (detail_template); pages don't
        // pay for the lookup. The Cms lookup lives here so Theme stays Cms-agnostic.
        $categoryTemplate = $entry->type === 'post'
            ? $entry->taxonomies()->first()?->detail_template
            : null;

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
        );

        return response($html);
    }

    private function renderCategory(ResolvedCategory $resolved, string $locale): Response
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
        );

        return response($html);
    }
}
