<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Routing\RouteResolver;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Rendering\PageRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public storefront: resolve (locale, path) → published content within the current
 * site (RLS-isolated), render it to SSR HTML through the theme. A missed path falls
 * back to a 301 (slug history) and otherwise 404s. The inbound path is lower-cased to
 * match the all-lowercase slug/route invariant, so case-variant links still resolve.
 * Runs inside the tenant context established by SetCurrentSite.
 */
final class StorefrontController
{
    public function __construct(
        private readonly RouteResolver $resolver,
        private readonly PageRenderer $renderer,
        private readonly SiteLocale $locale,
    ) {}

    public function __invoke(Request $request): Response
    {
        $path = strtolower('/'.ltrim($request->path(), '/'));
        $locale = $this->locale->default();

        $content = $this->resolver->resolve($locale, $path);

        if ($content === null) {
            $redirect = $this->resolver->redirect($locale, $path);
            abort_if($redirect === null, 404);

            return redirect()->to($redirect->to_path, $redirect->status_code);
        }

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
}
