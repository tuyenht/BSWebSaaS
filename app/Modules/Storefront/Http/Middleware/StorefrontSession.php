<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Middleware;

use App\Modules\Shared\Support\Cast;
use Closure;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * The session of a public page, in place of the web group's StartSession / ShareErrorsFromSession /
 * PreventRequestForgery (routes/web.php takes those off the storefront GET routes). A visitor who
 * brings no session cookie gets none: no session is started, no cookie is set, and the page can be
 * kept by the edge (EdgeCacheHeaders). A visitor who brings one — a signed-in member of the admin on
 * the same host, or someone whose form was just sent (the POST keeps the web group, so its outcome
 * comes back through the session) — gets the session as before, and the answer is theirs alone:
 * `private, no-store`.
 *
 * Without a session the views still get an empty error bag: under Octane the view factory outlives
 * the request, so a bag shared by an earlier request must never be what this page sees.
 */
final readonly class StorefrontSession
{
    public const string PRIVATE = 'private, no-store';

    public function __construct(private StartSession $session, private ShareErrorsFromSession $errors, private Factory $view) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->cookies->has(Cast::string(config('session.cookie')))) {
            $this->view->share('errors', new ViewErrorBag);

            return $next($request);
        }

        /** @var Response $response */
        $response = $this->session->handle($request, fn (Request $request): mixed => $this->errors->handle($request, $next));
        $response->headers->set('Cache-Control', self::PRIVATE);

        return $response;
    }
}
