<?php

namespace App\Modules\Admin\Support;

/**
 * Resolves which admin plane the current request is on so the shared auth components/views
 * (login, 2FA + email-OTP challenges) can redirect within the right plane:
 *  - 'platform' — the Bảo Sơn operator console on the platform host (no tenant context).
 *  - 'admin'    — a tenant's admin on the tenant's own host (default).
 *
 * Route names mirror per plane (admin.login ↔ platform.login, …), so prefix() picks the set.
 * Defaulting to 'admin' keeps every existing tenant route untouched.
 */
final class AdminPlane
{
    public static function prefix(): string
    {
        return request()->getHost() === config('tenancy.platform_host') ? 'platform' : 'admin';
    }

    public static function isPlatform(): bool
    {
        return self::prefix() === 'platform';
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters = []): string
    {
        return route(self::prefix().'.'.$name, $parameters);
    }
}
