<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Middleware;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\PreviewMode;
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
 */
final readonly class EdgeCacheHeaders
{
    /** What Symfony sends for a response nobody gave caching rules: the controller left them to us. */
    private const string UNSET = 'no-cache, private';

    public function __construct(private CookieJar $cookies) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $rules = null): Response
    {
        $response = $next($request);
        $config = $rules === null ? 'storefront.edge_cache' : 'storefront.edge_cache.'.$rules;

        // rules named but not configured: nothing is shared
        $known = $rules === null || is_array(config($config));

        $response->headers->set('Cache-Control', $known && $this->shareable($request, $response, Cast::strings(config($config.'.query_allow')))
            ? sprintf(
                'public, max-age=0, s-maxage=%d, stale-while-revalidate=%d',
                max(0, Cast::int(config($config.'.s_maxage'), 300)),
                max(0, Cast::int(config($config.'.stale_while_revalidate'), 86400)),
            )
            : StorefrontSession::PRIVATE);

        return $response;
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
