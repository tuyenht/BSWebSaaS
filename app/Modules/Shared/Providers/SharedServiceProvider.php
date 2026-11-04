<?php

declare(strict_types=1);

namespace App\Modules\Shared\Providers;

use App\Modules\Shared\Support\RichTextSanitizer;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the shared services that more than one module resolves, so each one is registered where it
 * lives rather than by whichever consumer happened to need it first. Shared depends on no module,
 * so neither does this provider.
 */
final class SharedServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // One per worker: it holds only the purifier built for each profile from constants (no
        // request state), so sharing it across Octane requests is safe, and building HTMLPurifier
        // once per worker instead of once per save is the point. Catalog, Cms (the body casts),
        // Theme (the print check) and Provisioning all resolve it.
        $this->app->singleton(RichTextSanitizer::class);
    }
}
