<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Storefront\Support\SiteMaintenance;
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
 *
 * An active site its owner has put under maintenance (SiteMaintenance) answers with the maintenance
 * page too, except to a signed-in member of the site (or a platform operator), who sees it as usual
 * to check the work. Only a request that brought the session cookie has a session here
 * (StorefrontSession: its answer is then `private, no-store`, so the page a member sees is never
 * kept for anyone else); a bare or forged cookie with no signed-in member behind it is a visitor.
 * The maintenance setting rides the page's one settings read, so a visitor's request reads nothing
 * more; the member check costs a signed-in request two lookups, only while maintenance is on.
 * Sitemap and robots keep answering (crawlers read the 503 + Retry-After of each page, the standard
 * signal of a short closure); the admin and its sign-in never pass through here.
 */
final readonly class ShowSiteStatePage
{
    public function __construct(
        private Entitlement $entitlement,
        private SiteStatePage $page,
        private SiteMaintenance $maintenance,
        private PlatformAccess $platform,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->entitlement->siteStatus();

        if ($status === 'active') {
            // A path with a dot is a scanner's (StorefrontController): it keeps its plain 404 and
            // reads none of the site's settings, maintenance or not.
            if (str_contains(rawurldecode($request->path()), '.')) {
                return $next($request);
            }
            $maintenance = $this->maintenance->active();

            return $maintenance === null || $this->isMember($request)
                ? $next($request)
                : $this->page->maintenance($request, $maintenance, $this->maintenance->zone());
        }

        $state = SiteState::tryFrom((string) $status);
        abort_if($state === null || $state === SiteState::Maintenance, 403);

        return $this->page->render($request, $state);
    }

    /** A signed-in member of this site (a role on it), or a platform operator, on a request with a session. */
    private function isMember(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }
        $user = $request->user();

        return $user instanceof User && ($this->platform->isSuperAdmin($user) || $user->roles()->exists());
    }
}
