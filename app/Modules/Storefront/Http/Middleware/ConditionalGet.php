<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conditional GET for the public pages, sitemaps and robots.txt: a strong ETag (a hash of the
 * final body) and, when the visitor or crawler sends the same tag back in If-None-Match, a 304
 * with no body. A crawler that gets 304 skips the download and the parse, which is what lets it
 * visit more of the site per day.
 *
 * Only a finished 200 answer to GET gets a tag (the router has already emptied a HEAD answer, so
 * there is no body to hash and HEAD is left as it is): redirects, errors and anything marked
 * no-store (previews) are left alone. Nothing is kept between requests; the tag is computed from
 * the response in hand, so it is safe under Octane. Cache-Control and every other header
 * (including the session cookies the web group adds around this) stay as they were: a 304 keeps
 * them, only the body and the content headers go.
 *
 * Runs inside the route, after the page is rendered, so the hash covers the body the visitor gets.
 */
final class ConditionalGet
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET')) {
            return $response;
        }
        if ($response->getStatusCode() !== 200 || $response->headers->has('ETag') || $response->isRedirection()) {
            return $response;
        }
        if (str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || $content === '') {
            return $response; // streamed or file responses have no body in hand
        }

        $response->setEtag(hash('xxh128', $content));
        $response->isNotModified($request);

        return $response;
    }
}
