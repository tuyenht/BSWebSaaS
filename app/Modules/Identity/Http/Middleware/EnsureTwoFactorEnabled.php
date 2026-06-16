<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory 2FA gate (design.md §29): a user on this plane must have confirmed TOTP.
 * Not yet enrolled → sent to the 2FA setup flow. Applied to the platform plane;
 * tenant 2FA is optional.
 */
final class EnsureTwoFactorEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->two_factor_confirmed_at === null) {
            return redirect()->route('two-factor.setup');
        }

        return $next($request);
    }
}
