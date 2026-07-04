<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Forms\Services\TurnstileVerifier;

/**
 * Whether the Cloudflare Turnstile challenge is ACTIVE on the login form. Two conditions:
 *   1. the CF keys are configured (TurnstileVerifier::isConfigured — both site + secret keys), AND
 *   2. the operator has left the system-wide toggle ON (platform_settings 'security'
 *      .turnstile_login_enabled — default ON, so it fails safe until explicitly disabled).
 *
 * This login-specific policy lives in the Admin module — NOT baked into TurnstileVerifier, which the
 * storefront lead form also uses. Both login gate points (Login::login() + the turnstile-field partial)
 * call active() so render and enforcement never disagree.
 */
final class LoginTurnstile
{
    public const string GROUP = 'security';

    public const string KEY = 'turnstile_login_enabled';

    public static function active(): bool
    {
        return app(TurnstileVerifier::class)->isConfigured() && self::enabled();
    }

    /**
     * The system-wide toggle. Absent (never saved) → ON; only an explicit `false` disables it.
     */
    public static function enabled(): bool
    {
        $security = app(PlatformSettings::class)->get(self::GROUP);

        return ! is_array($security) || ($security[self::KEY] ?? true) !== false;
    }
}
