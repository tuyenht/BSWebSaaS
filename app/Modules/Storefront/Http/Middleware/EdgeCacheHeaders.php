<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Middleware;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PreviewMode;
use App\Modules\Storefront\EdgeCache\PageEndTime;
use App\Modules\Storefront\EdgeCache\PurgeSiteEdgeCache;
use App\Modules\Storefront\Support\SiteEmbedCode;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Theme\Theming\StorefrontSettings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may keep a public page. A finished 200 to GET or HEAD, for a visitor with no session, outside
 * a preview, with no cookie on the way out and a query string made of `storefront.edge_cache.query_allow`
 * keys only, is the same bytes for every visitor of that address: the edge may keep it for
 * `s_maxage` seconds (browsers always ask again), and serve it a while longer while it refetches.
 * Anything else that reaches here — a 404, a redirect, any other key, a page the controller already
 * marked private — answers `private, no-store`, so no shared cache ever stores it.
 *
 * A request carrying an X-Forwarded-Host, -Port or -Prefix that disagrees with its own Host header is
 * kept private too: the edge files the copy under Host, so a page built for any other address must
 * never land there (the app takes the host from Host alone; this holds even if that ever slips).
 *
 * The innermost storefront middleware: it sees the page as the controller made it, and
 * ConditionalGet (outside it) turns a match into a 304 that keeps these headers. Answers made before
 * the route ran (redirects to the primary domain, an unknown host, a closed site) keep their own.
 *
 * A route may name its own rules (`EdgeCacheHeaders:suggest` reads `storefront.edge_cache.suggest`):
 * its own times and its own query keys in place of the pages', every other condition the same; a
 * name with no rules shares nothing.
 *
 * The pages' own lifetime is long because a change purges them (Storefront\EdgeCache), and is cut
 * where no purge reaches: on a host outside the platform's zone (a customer's own domain, which our
 * purge cannot name) to `unpurged_s_maxage`, and on every host to the site's next midnight (at least
 * a minute), when what a page shows by date alone — a job's deadline state, "closing soon" — moves
 * with no write to purge it. A page whose item has an end time («Gỡ khỏi website lúc», PageEndTime)
 * is kept only until then, with no stale window: the edge never shows it past its end, even when the
 * purge of the sweep that takes it down fails.
 */
final readonly class EdgeCacheHeaders
{
    /** What Symfony sends for a response nobody gave caching rules: the controller left them to us. */
    private const string UNSET = 'no-cache, private';

    /** The shortest a page is kept when the site's midnight is seconds away. */
    private const int MIDNIGHT_FLOOR = 60;

    public function __construct(private CookieJar $cookies, private StorefrontSettings $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $rules = null): Response
    {
        $response = $next($request);
        $config = $rules === null ? 'storefront.edge_cache' : 'storefront.edge_cache.'.$rules;

        // rules named but not configured: nothing is shared
        $known = $rules === null || is_array(config($config));

        $shared = $known && $this->shareable($request, $response, Cast::strings(config($config.'.query_allow')));
        // the lifetime is worked out only for a page that is shared (it may read the site's settings)
        $lifetime = fn (): int => $rules === null ? $this->pageLifetime($request) : max(0, Cast::int(config($config.'.s_maxage'), 300));
        $response->headers->set('Cache-Control', match (true) {
            ! $shared => StorefrontSession::PRIVATE,
            // a page carrying the website's extra embed code: a browser may never show its kept copy
            // without asking first (no stale window), so a visitor who has signed in since gets the
            // page drawn for a session, without the code
            app(SiteEmbedCode::class)->printed() => sprintf('public, max-age=0, must-revalidate, s-maxage=%d', $lifetime()),
            default => sprintf('public, max-age=0, s-maxage=%d, stale-while-revalidate=%d', $lifetime(), $rules === null && app(PageEndTime::class)->secondsLeft() !== null ? 0 : max(0, Cast::int(config($config.'.stale_while_revalidate'), 60))),
        });

        return $response;
    }

    /** How long the edge may keep a public page of this host: the configured day, cut as the class says. */
    private function pageLifetime(Request $request): int
    {
        $seconds = max(0, Cast::int(config('storefront.edge_cache.s_maxage'), 86400));

        if (PurgeSiteEdgeCache::inZone([$request->getHost()]) === []) {
            $seconds = min($seconds, max(0, Cast::int(config('storefront.edge_cache.unpurged_s_maxage'), 300)));
        }

        // the zone rides the page's one settings read (SiteTime would query again)
        $now = CarbonImmutable::now(SiteTime::zoneFrom($this->settings->get('general', 'timezone')));
        $midnight = $now->addDay()->startOfDay();

        $seconds = min($seconds, max(self::MIDNIGHT_FLOOR, $midnight->getTimestamp() - $now->getTimestamp()));

        // the item leaves the website at its end time: never kept past it
        $left = app(PageEndTime::class)->secondsLeft();

        return $left !== null ? min($seconds, $left) : $seconds;
    }

    /** @param list<string> $queryAllow */
    private function shareable(Request $request, Response $response, array $queryAllow): bool
    {
        $headers = $response->headers;

        return $request->isMethodCacheable()
            && $response->getStatusCode() === Response::HTTP_OK
            && ! $request->hasSession()
            && ! PreviewMode::on()
            && ! self::forwardedElsewhere($request)
            && $headers->get('Cache-Control') === self::UNSET
            && $headers->getCookies() === []
            && $this->cookies->getQueuedCookies() === []
            && array_diff(array_map('strval', array_keys($request->query->all())), $queryAllow) === [];
    }

    /** Whether the proxy headers name another host, port or path prefix than Host itself. */
    private static function forwardedElsewhere(Request $request): bool
    {
        $headers = $request->headers;
        $host = strtolower(rtrim(trim((string) $headers->get('host', '')), '.'));

        $forwardedHost = $headers->get('x-forwarded-host');
        if ($forwardedHost !== null && strtolower(rtrim(trim(explode(',', $forwardedHost)[0]), '.')) !== $host) {
            return true;
        }

        $forwardedPort = $headers->get('x-forwarded-port');
        if ($forwardedPort !== null) {
            $colon = strrpos($host, ':');
            $port = $colon !== false && ! str_ends_with($host, ']') ? substr($host, $colon + 1) : ($request->isSecure() ? '443' : '80');
            if (trim(explode(',', $forwardedPort)[0]) !== $port) {
                return true;
            }
        }

        $prefix = $headers->get('x-forwarded-prefix');

        return $prefix !== null && trim($prefix) !== '' && trim($prefix) !== '/';
    }
}
