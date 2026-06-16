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
final class PageRenderer
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly TemplateResolver $templates,
        private readonly BlockRenderer $blocks,
        private readonly ThemeResolver $theme,
    ) {}

    /**
     * @param  array<string, mixed>  $body  the Page-JSON body
     * @param  array{title?: string|null, description?: string|null}  $seo
     */
    public function render(string $type, ?string $templateOverride, string $title, array $body, array $seo = []): string
    {
        $layout = $this->templates->layoutFor($type, $templateOverride);

        return $this->views->make($layout, [
            'title' => $title,
            'seoTitle' => $seo['title'] ?? $title,
            'seoDescription' => $seo['description'] ?? null,
            'tokens' => $this->theme->tokens(),
            'sections' => $this->blocks->render($body),
        ])->render();
    }
}
