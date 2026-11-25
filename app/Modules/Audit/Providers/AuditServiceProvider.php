<?php

declare(strict_types=1);

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Console\EnsureAuditMonths;
use App\Modules\Audit\Console\PruneAuditMonths;
use App\Modules\Audit\Support\DateChangeAudit;
use Illuminate\Support\ServiceProvider;

/** The audit trail's own commands: the monthly tables of the log (create ahead, prune old). */
final class AuditServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // which road a date change came by: per request, never carried into the next (Octane)
        $this->app->scoped(DateChangeAudit::class);
    }

    public function boot(): void
    {
        // Module commands aren't auto-discovered like app/Console/Commands.
        if ($this->app->runningInConsole()) {
            $this->commands([EnsureAuditMonths::class, PruneAuditMonths::class]);
        }
    }
}
