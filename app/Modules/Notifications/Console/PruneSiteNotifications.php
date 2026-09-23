<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;

/**
 * Retention: notifications are kept 90 days (owner decision 24/09), then removed — site by site,
 * inside each site's tenant context (RLS), through the (site_id, created_at) index. Scheduled
 * daily (routes/console.php).
 */
final class PruneSiteNotifications extends Command
{
    #[\Override]
    protected $signature = 'notifications:prune {--days=90 : Keep this many days of notifications}';

    #[\Override]
    protected $description = 'Remove in-app notifications older than the retention period, on every site.';

    public function handle(Tenancy $tenancy): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        $sites = $tenancy->eachSite(function () use ($cutoff, &$deleted): void {
            $removed = SiteNotification::query()->where('created_at', '<', $cutoff)->delete();
            $deleted += is_int($removed) ? $removed : 0;
        });

        $this->info("Removed {$deleted} notifications older than {$days} days on {$sites} sites.");

        return self::SUCCESS;
    }
}
