<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Storefront\Http\Middleware\StorefrontSession;
use App\Modules\Storefront\Search\SearchSuggestions;
use App\Modules\Storefront\Search\SearchText;
use App\Modules\Storefront\Support\SiteMaintenance;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Chrome\StorefrontChrome;
use App\Modules\Theme\Theming\StorefrontSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The suggestions under a search box while the visitor types, `/tim-kiem/goi-y?q=…` (`/{locale}/…` for
 * a language other than the site's default, as the search page itself): JSON
 * `{q, items: [{kind, label, parts, group, group_key, icon, url, thumb, meta, meta_parts?, sku?}], total, total_more, all_url}`
 * (SearchSuggestions), the script drawing each row from plain text under its group's heading. `total`:
 * how many results the search page lists, counted up to SearchSuggestions::COUNT_CAP (the box shows
 * «50+» past 50; `total_more`: at least that many), null with no row. Nothing typed, or one letter: no rows. While the site has
 * search switched off or under maintenance (SiteMaintenance: this route has no session, so no member
 * is told apart from a visitor), or under a prefix that is not a public language of the site, the
 * address does not exist (`{}` with a 404): it never serves a content page.
 *
 * Never indexed. An answer is the same for every visitor of the address (published titles only, no
 * session on this route), so the edge may keep it a minute (EdgeCacheHeaders' `suggest` rules: a
 * visitor typing what another just typed gets it from the edge, never from here); a refusal, a 404 and
 * a redirect are never kept. Typing asks several times a second, so it has its own limit per site and
 * visitor address (MAX_PER_MINUTE, counted in the cache store, apart from the search page's): past it
 * the answer is `{}` with a 429, which the box shows as no suggestion. The words typed are never stored
 * here with the address (the counter's key holds the address alone) and never written to the log.
 */
final readonly class SearchSuggestController
{
    /** Suggestion requests one visitor address may make on one site per minute. */
    public const int MAX_PER_MINUTE = 120;

    public function __construct(
        private SiteLocale $locale,
        private StorefrontSettings $settings,
        private SearchSuggestions $suggestions,
        private PathBuilder $paths,
        private CurrentSite $current,
        private SiteMaintenance $maintenance,
    ) {}

    public function __invoke(Request $request, ?string $locale = null): Response
    {
        $default = $this->locale->default();
        if ($locale !== null) {
            $locale = strtolower($locale);
            if ($locale === $default) {
                // the default language has no prefix: one address
                $query = $request->getQueryString();

                return redirect()->to(PathBuilder::SUGGEST_PATH.($query === null || $query === '' ? '' : '?'.$query), 301);
            }
            if (! $this->locale->isPublic($locale)) {
                // not a language of the site: no suggestions there, and never a content page under this route's middleware
                return self::private(new JsonResponse(new \stdClass, 404));
            }
        }
        $locale ??= $default;

        if (! StorefrontChrome::searchOn($this->settings->get('storefront', 'header')) || $this->maintenance->on()) {
            return self::private(new JsonResponse(new \stdClass, 404));
        }
        app()->setLocale($locale);

        $q = SearchText::from($request->query('q'));
        $all = $this->paths->searchUrl($locale);
        if (! $q->searchable()) {
            return self::answer(new JsonResponse(['q' => $q->text, 'items' => [], 'total' => null, 'total_more' => false, 'all_url' => $all]));
        }

        $key = 'site-suggest:'.$this->current->id().'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_MINUTE)) {
            $busy = self::private(new JsonResponse(new \stdClass, 429));
            $busy->headers->set('Retry-After', (string) max(1, RateLimiter::availableIn($key)));

            return $busy;
        }
        RateLimiter::hit($key, 60);

        $items = $this->suggestions->build($q, $locale);
        // counted only for a box that shows rows: «Xem tất cả N kết quả» sits under them
        $total = $items === [] ? null : $this->suggestions->total($q, $locale);

        return self::answer(new JsonResponse([
            'q' => $q->text,
            'items' => $items,
            'total' => $total['count'] ?? null,
            'total_more' => $total['lower'] ?? false,
            'all_url' => $all.'?'.http_build_query(['q' => $q->text]),
        ], 200, [], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE));
    }

    /** The same for every visitor: who may keep it is EdgeCacheHeaders' call. */
    private static function answer(JsonResponse $response): JsonResponse
    {
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /** A refusal or an absence: this visitor's alone, never kept. */
    private static function private(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', StorefrontSession::PRIVATE);

        return self::answer($response);
    }
}
