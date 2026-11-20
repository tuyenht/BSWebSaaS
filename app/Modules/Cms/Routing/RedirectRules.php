<?php

declare(strict_types=1);

namespace App\Modules\Cms\Routing;

use App\Modules\Cms\Models\Redirect;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Routing\Router;

/**
 * What a stored redirect may do, checked the same way where a row is written («Chuyển hướng») and
 * where it is followed (the storefront):
 *
 *  - its old address is never one the application's own routes use on a website's host — the sign-in
 *    pages, /admin, Livewire, file and health routes… (reservedFrom): a redirect there would either
 *    never run or put a sign-in address under the website's control. The list is read from the
 *    route definitions, never written down: every route not bound to the platform's own host, by the
 *    first part of its address;
 *  - it leads to a path on this website (SafeLink::isSitePath), or — only while the website's
 *    `redirects.external` switch is on — to another website's address in the one form
 *    SafeLink::externalHttps() gives. A row that is neither is never followed.
 */
final readonly class RedirectRules
{
    /** The website switch a super_admin turns on to let redirects lead to another website. */
    public const string EXTERNAL_FEATURE = 'redirects.external';

    // Only the router (one per worker) is held: the entitlement is request-scoped, so it is asked
    // for each time — a storefront controller holding this class outlives a request under Octane.
    public function __construct(private Router $router) {}

    /** Whether the current website may send visitors to another website. */
    public function externalOn(): bool
    {
        return app(Entitlement::class)->featureEnabled(self::EXTERNAL_FEATURE);
    }

    /** Whether $path (as stored: "/…", lower-case, no language prefix) is under an address the application's routes own. */
    public function reservedFrom(string $path): bool
    {
        $first = strtolower(explode('/', ltrim($path, '/'), 2)[0]);

        return $first !== '' && in_array(rawurldecode($first), $this->reservedSegments(), true);
    }

    /** Whether the storefront follows $row. */
    public function follows(Redirect $row): bool
    {
        if ($this->reservedFrom($row->from_path)) {
            return false;
        }
        if (SafeLink::isSitePath($row->to_path)) {
            return true;
        }

        return SafeLink::isExternalHttps($row->to_path) && $this->externalOn();
    }

    /** Whether $row leads to another website (whatever the switch says). */
    public static function isExternal(Redirect $row): bool
    {
        return SafeLink::isExternalHttps($row->to_path);
    }

    /**
     * The first parts of the addresses the routes answer on any host: "admin", "login", "livewire"…
     * A route bound to a host (the platform console) answers only there, so it reserves nothing on a
     * website; the storefront's catch-all and routes that start with a parameter ("{locale}/…")
     * name no fixed part.
     *
     * @return list<string>
     */
    public function reservedSegments(): array
    {
        $segments = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if ($route->isFallback || $route->getDomain() !== null) {
                continue;
            }
            $first = strtolower(explode('/', trim($route->uri(), '/'), 2)[0]);
            if ($first !== '' && ! str_contains($first, '{')) {
                $segments[$first] = true;
            }
        }

        return array_keys($segments);
    }
}
