<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Routing\RouteResolver;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Theme\Rendering\PageRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public storefront: resolve (locale, path) → published content within the current
 * site (RLS-isolated), render it to SSR HTML through the theme. No content = 404.
 * Runs inside the tenant context established by SetCurrentSite.
 */
final class StorefrontController
{
    public function __construct(
        private readonly RouteResolver $resolver,
        private readonly PageRenderer $renderer,
    ) {}

    public function __invoke(Request $request): Response
    {
        $path = '/'.ltrim($request->path(), '/');
        $locale = $this->defaultLocale();

        $content = $this->resolver->resolve($locale, $path);
        abort_if($content === null, 404);

        $html = $this->renderer->render(
            $content->entry->type,
            $content->entry->template_override,
            $content->translation->title,
            $content->translation->body,
            [
                'title' => $content->translation->seo_title,
                'description' => $content->translation->seo_description,
            ],
        );

        return response($html);
    }

    private function defaultLocale(): string
    {
        $siteId = app(CurrentSite::class)->id();
        $locale = Site::query()->whereKey($siteId)->value('default_locale');

        return is_string($locale) && $locale !== '' ? $locale : 'vi';
    }
}
