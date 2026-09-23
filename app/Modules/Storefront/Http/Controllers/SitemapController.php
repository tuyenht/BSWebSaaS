<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant-scoped sitemap.xml: emits a valid XML urlset for every route on this site a visitor
 * can actually open. Runs inside the tenant context (SetCurrentSite + RLS) so BelongsToSite
 * global scopes ensure no cross-tenant leakage. Locale-prefixed absolute URLs are built by the
 * shared PathBuilder (the same chokepoint used by the storefront controller).
 *
 * A route is listed only when the storefront would render it — a URL that 404s costs the site
 * crawl budget and shows up as an error in Google Search Console:
 *  - its language is one the site has made public;
 *  - an entry route: the entry is published (soft-deletes respected) AND its translation in the
 *    route's language is published;
 *  - a category route: the category has a translation in the route's language;
 *  - a product route: the product is published (soft-deletes respected) and has a translation in
 *    the route's language. Cms's SiteRoute stays Catalog-agnostic (no product relation), so the
 *    Storefront matches product_id against a Catalog subquery here.
 *
 * This request is its own hot path — queries here are not subject to the per-page hot-path
 * Query Budget, but N+1 is still avoided: one query for all routes, the checks being correlated
 * subqueries rather than id lists loaded into PHP.
 */
final readonly class SitemapController
{
    public function __construct(
        private PathBuilder $paths,
        private SiteLocale $locales,
    ) {}

    public function __invoke(Request $request): Response
    {
        $routes = SiteRoute::query()
            ->whereIn('locale', $this->locales->public())
            ->where(function (Builder $query): void {
                $query->whereHas('entry', fn (Builder $entry) => $entry
                    ->where('status', 'published')
                    ->whereHas('translations', fn (Builder $translation) => $translation
                        ->whereColumn('entry_translations.locale', 'routes.locale')
                        ->where('entry_translations.status', 'published')))
                    ->orWhereHas('taxonomy', fn (Builder $taxonomy) => $taxonomy
                        ->whereHas('translations', fn (Builder $translation) => $translation
                            ->whereColumn('taxonomy_translations.locale', 'routes.locale')))
                    ->orWhereIn('product_id', Product::query()
                        ->select('products.id')
                        ->where('status', 'published')
                        ->whereHas('translations', fn (Builder $translation) => $translation
                            ->whereColumn('product_translations.locale', 'routes.locale')));
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
