<?php

namespace App\Modules\Theme\Rendering;

use App\Modules\Theme\Theming\ThemeResolver;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Renders resolved content to a full SSR HTML page on the SHARED master template:
 * pick the layout (TemplateResolver), render the Page-JSON blocks (BlockRenderer),
 * apply per-site design tokens (ThemeResolver), wrap in the layout. Takes primitives,
 * not Cms models, so the Theme module stays independent of Cms.
 */
final readonly class PageRenderer
{
    public function __construct(
        private ViewFactory $views,
        private TemplateResolver $templates,
        private BlockRenderer $blocks,
        private ThemeResolver $theme,
    ) {}

    /**
     * @param  array<string, mixed>  $body  the Page-JSON body
     * @param  array{title?: string|null, description?: string|null}  $seo
     * @param  string|null  $categoryTemplate  per-category layout name (override → category → type)
     * @param  array<string, mixed>  $extra  extra layout vars (e.g. a post's category breadcrumb)
     */
    public function render(string $type, ?string $templateOverride, string $title, array $body, array $seo = [], ?string $categoryTemplate = null, array $extra = []): string
    {
        $layout = $this->templates->layoutFor($type, $templateOverride, $categoryTemplate);

        return $this->views->make($layout, [
            'title' => $title,
            'seoTitle' => $seo['title'] ?? $title,
            'seoDescription' => $seo['description'] ?? null,
            'tokens' => $this->theme->tokens(),
            'sections' => $this->blocks->render($body),
        ] + $extra)->render();
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
        $layout = $this->templates->layoutFor('category', null, $listTemplate);

        return $this->views->make($layout, [
            'title' => $title,
            'seoTitle' => $seo['title'] ?? $title,
            'seoDescription' => $seo['description'] ?? null,
            'tokens' => $this->theme->tokens(),
            'posts' => $posts,
            'pagination' => $pagination,
        ] + $extra)->render();
    }
}
