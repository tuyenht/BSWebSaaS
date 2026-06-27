<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
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
    }
}
