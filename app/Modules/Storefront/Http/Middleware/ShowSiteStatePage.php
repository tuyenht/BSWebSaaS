<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Middleware;

use App\Modules\Storefront\Support\SiteState;
use App\Modules\Storefront\Support\SiteStatePage;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The lifecycle gate of the storefront's pages, in place of `site.active` (EnsureSiteActive) on
 * the page route: an active site goes on to its page; a site being set up, suspended or closed
 * answers with its state page (SiteStatePage) instead of a bare 403, and nothing of its content is
 * read. Any other status — or none — is refused as before (fail-closed). Runs after SetCurrentSite,
 * inside the site's context. Every other route of the site (admin, forms, sitemap, robots) keeps
 * `site.active`.
 */
final readonly class ShowSiteStatePage
{
    public function __construct(private Entitlement $entitlement, private SiteStatePage $page) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->entitlement->siteStatus();

        if ($status === 'active') {
            return $next($request);
        }

        $state = SiteState::tryFrom((string) $status);
        abort_if($state === null, 403);

        return $this->page->render($request, $state);
    }
}
