<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Illuminate\Routing\Router;

/**
 * The first path segments the application itself answers on every website's own host (`login`,
 * `admin`, `livewire-…`): read from the router, once per request, never from a hand-written list.
 * A flat product address equal to one of them would never be served, so it is refused up front.
 * Routes bound to a host (the platform's) and catch-all parameters are not part of it.
 */
final class AppRoutePaths
{
    /** @var array<string, true>|null */
    private ?array $segments = null;

    public function __construct(private readonly Router $router) {}

    public function owns(string $segment): bool
    {
        return isset($this->all()[mb_strtolower($segment)]);
    }

    /**
     * @return array<string, true>
     */
    private function all(): array
    {
        if ($this->segments !== null) {
            return $this->segments;
        }

        $segments = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if ($route->getDomain() !== null) {
                continue;
            }
            $first = explode('/', trim($route->uri(), '/'))[0];
            if ($first === '' || str_starts_with($first, '{')) {
                continue;
            }
            $segments[mb_strtolower($first)] = true;
        }

        return $this->segments = $segments;
    }
}
