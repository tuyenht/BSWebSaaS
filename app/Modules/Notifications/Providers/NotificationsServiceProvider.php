<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Console\PruneSiteNotifications;
use Illuminate\Support\ServiceProvider;

/**
 * In-app notifications (design.md §15, decision Q5): the site_notifications store, its channel,
 * per-person preferences and the reader's feed. Depends on Tenancy + Identity only; content
 * modules (Cms, Forms) and the admin send through it, never the other way round.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Module commands aren't auto-discovered like app/Console/Commands.
        if ($this->app->runningInConsole()) {
            $this->commands([PruneSiteNotifications::class]);
        }
    }
}
