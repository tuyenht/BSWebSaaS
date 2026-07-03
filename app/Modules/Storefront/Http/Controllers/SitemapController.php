<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant-scoped sitemap.xml: emits a valid XML urlset for every published route on this
 * site. Runs inside the tenant context (SetCurrentSite + RLS) so BelongsToSite global
 * scopes ensure no cross-tenant leakage. Locale-prefixed absolute URLs are built by the
 * shared PathBuilder (the same chokepoint used by the storefront controller).
 *
 * Included routes:
 *  - Entry routes where the entry is published (status = 'published', soft-deletes respected).
 *  - Taxonomy routes (category-list pages; taxonomies have no publish/draft lifecycle).
 *
 * This request is its own hot path — queries here are not subject to the per-page hot-path
 * Query Budget, but N+1 is still avoided (one query for all routes).
 */
final readonly class SitemapController
{
    public function __construct(private PathBuilder $paths) {}

    public function __invoke(Request $request): Response
    {
        $routes = SiteRoute::query()
            ->where(function (Builder $query): void {
                // Published entry routes (soft-delete scope respected via the Entry model).
                $query->whereHas('entry', fn (Builder $e) => $e->where('status', 'published'))
                    // Taxonomy (category-list) routes: always reachable, no publish lifecycle.
                    ->orWhereNotNull('taxonomy_id');
            })
            ->orderBy('path')
            ->get(['path', 'locale', 'updated_at']);

        $base = $request->getScheme().'://'.$request->getHost();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($routes as $route) {
            $loc = htmlspecialchars(
                $base.$this->paths->publicUrl($route->path, $route->locale),
                ENT_XML1 | ENT_QUOTES,
                'UTF-8',
            );
            $xml .= '<url><loc>'.$loc.'</loc>';
            if ($route->updated_at !== null) {
                $xml .= '<lastmod>'.htmlspecialchars((string) $route->updated_at->toAtomString(), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</lastmod>';
            }
            $xml .= '</url>';
        }

        $xml .= '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
