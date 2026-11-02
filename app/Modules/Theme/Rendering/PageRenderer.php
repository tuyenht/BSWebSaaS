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
        /** @var view-string $layout a theme layout name (TemplateResolver) */
        $layout = $this->templates->layoutFor($type, $templateOverride, $categoryTemplate);
        $leadBlock = $this->blocks->leadType($body);

        return $this->views->make($layout, [
            'title' => $title,
            'seoTitle' => $seo['title'] ?? $title,
            'seoDescription' => $seo['description'] ?? null,
            'tokens' => $this->theme->cssVariables(),
            'sections' => $this->blocks->render(self::withPageTitle($body, $title, $leadBlock)),
            // A page_header band leading the page renders the h1 itself; the layout then skips its own.
            'leadBlock' => $leadBlock,
        ] + $extra)->render();
    }

    /**
     * Prepare the page_header blocks: one without its own title shows the page title, and only
     * the band that leads the page is marked as the page's h1 (`_lead`, always set here so a
     * stored value never counts) — any later band is an h2.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withPageTitle(array $body, string $title, ?string $leadType): array
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
            $block['props'] = $props;
            $blocks[$index] = $block;
        }
        $body['blocks'] = $blocks;

        return $body;
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
        /** @var view-string $layout a theme layout name (TemplateResolver) */
        $layout = $this->templates->layoutFor('category', null, $listTemplate);

        return $this->views->make($layout, [
            'title' => $title,
            'seoTitle' => $seo['title'] ?? $title,
            'seoDescription' => $seo['description'] ?? null,
            'tokens' => $this->theme->cssVariables(),
            'posts' => $posts,
            'pagination' => $pagination,
        ] + $extra)->render();
    }

    /**
     * Render a product detail page: name + description HTML + gallery, on the product layout
     * (theme::layouts.product). Like render()/renderCategory(), takes PRIMITIVES (a pre-sanitized
     * description string + resolved image URLs) so the Theme module stays independent of Cms and
     * Catalog.
     *
     * @param  list<array{url: string, alt: string}>  $images
     * @param  array{title?: string|null, description?: string|null}  $seo
     * @param  array<string, mixed>  $extra  extra layout vars (chrome, canonical, og:*, breadcrumb)
     */
    public function renderProduct(string $name, string $descriptionHtml, array $images, array $seo = [], array $extra = []): string
    {
        /** @var view-string $layout a theme layout name (TemplateResolver) */
        $layout = $this->templates->layoutFor('product');

        return $this->views->make($layout, [
            'title' => $name,
            'seoTitle' => $seo['title'] ?? $name,
            'seoDescription' => $seo['description'] ?? null,
            'tokens' => $this->theme->cssVariables(),
            'description' => $descriptionHtml,
            'images' => $images,
        ] + $extra)->render();
    }
}
