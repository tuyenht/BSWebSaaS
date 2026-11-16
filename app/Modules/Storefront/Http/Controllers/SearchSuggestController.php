<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Storefront\Http\Middleware\StorefrontSession;
use App\Modules\Storefront\Search\SearchSuggestions;
use App\Modules\Storefront\Search\SearchText;
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
 * `{q, items: [{kind, label, parts, group, icon, url, sku?}], all_url}` (SearchSuggestions), the
 * script drawing each row from plain text. Nothing typed, or one letter: no rows. While the site has
 * search switched off the address does not exist (404).
 *
 * Never cached and never indexed. Typing asks several times a second, so it has its own limit per site
 * and visitor address (MAX_PER_MINUTE, counted in the cache store, apart from the search page's): past
 * it the answer is `{}` with a 429, which the box shows as no suggestion. The words typed are never
 * stored with the address (the counter's key holds the address alone) and never written to the log.
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
                // not a language of the site: an ordinary address, served by the storefront like any other path
                return app(StorefrontController::class)($request);
            }
        }
        $locale ??= $default;

        if (! StorefrontChrome::searchOn($this->settings->get('storefront', 'header'))) {
            return self::private(new JsonResponse(new \stdClass, 404));
        }
        app()->setLocale($locale);

        $q = SearchText::from($request->query('q'));
        $all = $this->paths->searchUrl($locale);
        if (! $q->searchable()) {
            return self::private(new JsonResponse(['q' => $q->text, 'items' => [], 'all_url' => $all]));
        }

        $key = 'site-suggest:'.$this->current->id().'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_MINUTE)) {
            $busy = self::private(new JsonResponse(new \stdClass, 429));
            $busy->headers->set('Retry-After', (string) max(1, RateLimiter::availableIn($key)));

            return $busy;
        }
        RateLimiter::hit($key, 60);

        return self::private(new JsonResponse([
            'q' => $q->text,
            'items' => $this->suggestions->build($q, $locale),
            'all_url' => $all.'?'.http_build_query(['q' => $q->text]),
        ], 200, [], JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE));
    }

    private static function private(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', StorefrontSession::PRIVATE);
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
