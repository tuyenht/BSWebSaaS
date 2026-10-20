<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Storefront\Support\SitemapRoutes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant-scoped sitemap.xml: lists every route on this site a visitor can actually open (the
 * filter lives in SitemapRoutes). Runs inside the tenant context (SetCurrentSite + RLS) so
 * BelongsToSite global scopes ensure no cross-tenant leakage. Locale-prefixed absolute URLs are
 * built by the shared PathBuilder (the same chokepoint used by the storefront controller).
 *
 * sitemaps.org caps one file at `cms.sitemap.max_urls` (50,000) URLs:
 *  - at or under it, /sitemap.xml is a single <urlset> — read with LIMIT max + 1, so a small
 *    site needs no count, no cache and no second query;
 *  - over it, /sitemap.xml is a <sitemapindex> of /sitemap-{n}.xml, each a keyset slice of at
 *    most max URLs. n is the page position, never a route id; out of range → 404.
 *
 * This request is its own hot path — queries here are not subject to the per-page hot-path
 * Query Budget, but N+1 is still avoided: one query per file, the checks being correlated
 * subqueries rather than id lists loaded into PHP.
 */
final readonly class SitemapController
{
    private const string XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public function __construct(
        private PathBuilder $paths,
        private SitemapRoutes $routes,
    ) {}

    public function __invoke(Request $request): Response
    {
        $max = $this->routes->maxUrls();
        $plan = $this->routes->cachedPlan();

        if ($plan === null || count($plan) <= 1) {
            $routes = $this->routes->slice(null, $max + 1);
            if ($routes->count() <= $max) {
                return $this->urlset($request, $routes);
            }

            $plan = $this->routes->plan();
            if (count($plan) <= 1) {
                // The site shrank between the two reads: still one file.
                return $this->urlset($request, $routes->take($max));
            }
        }

        $base = $this->base($request);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="'.self::XMLNS.'">';
        foreach (array_keys($plan) as $i) {
            $xml .= '<sitemap><loc>'.$this->escape($base.'/sitemap-'.($i + 1).'.xml').'</loc></sitemap>';
        }

        return $this->xml($xml.'</sitemapindex>');
    }

    public function page(Request $request, string $page): Response
    {
        $plan = $this->routes->plan();
        $n = (int) $page;

        // A site that fits in one file has no numbered pages: /sitemap.xml is all of it.
        abort_if(count($plan) <= 1 || $n < 1 || $n > count($plan), 404);

        return $this->urlset($request, $this->routes->slice($plan[$n - 1], $this->routes->maxUrls()));
    }

    /**
     * @param  Collection<int, SiteRoute>  $routes
     */
    private function urlset(Request $request, Collection $routes): Response
    {
        $base = $this->base($request);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="'.self::XMLNS.'">';

        foreach ($routes as $route) {
            $xml .= '<url><loc>'.$this->escape($base.$this->paths->publicUrl($route->path, $route->locale)).'</loc>';
            if ($route->updated_at !== null) {
                $xml .= '<lastmod>'.$this->escape((string) $route->updated_at->toAtomString()).'</lastmod>';
            }
            $xml .= '</url>';
        }

        return $this->xml($xml.'</urlset>');
    }

    private function base(Request $request): string
    {
        return $request->getScheme().'://'.$request->getHost();
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xml(string $xml): Response
    {
        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
