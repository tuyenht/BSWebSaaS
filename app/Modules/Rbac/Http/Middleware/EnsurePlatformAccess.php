<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the platform (Bảo Sơn operator) plane: the authenticated user must hold a
 * platform role (super_admin / support / billing). Runs after 'auth'.
 */
final readonly class EnsurePlatformAccess
{
    public function __construct(private PlatformAccess $platform) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $this->platform->isOperator($user),
            403,
        );

        return $next($request);
    }
}
