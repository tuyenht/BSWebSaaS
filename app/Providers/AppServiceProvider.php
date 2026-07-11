<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind FrankenPHP/Caddy, TLS is terminated at the edge and X-Forwarded-Proto is no longer
        // trusted from arbitrary proxies (trustProxies is narrowed for spoofing safety). When the
        // site is configured for https, force https URL generation so canonical / og:url / @vite
        // asset URLs are https and never mixed-content-blocked on the page.
        $appUrl = config('app.url');
        if (is_string($appUrl) && str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }

        // One password policy for every entry point (self-service change + reset): at least 10
        // characters and not present in a known breach corpus (HaveIBeenPwned k-anonymity range API —
        // only a 5-char SHA-1 prefix leaves the server). Fail-open on an outage so a third-party blip
        // never blocks a legitimate password change.
        Password::defaults(fn (): Password => Password::min(10)->uncompromised());
    }
}
