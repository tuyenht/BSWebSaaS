<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Console;

use App\Modules\Storefront\Models\ContactClickDaily;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Retention: contact button tallies are kept 90 days, then removed — website by website, inside each
 * website's tenant context (RLS), through the (site_id, day, …) unique index. A website that fails
 * is reported and the sweep moves on. Scheduled daily (routes/console.php).
 */
final class PruneContactClicks extends Command
{
    #[\Override]
    protected $signature = 'contact:prune-clicks {--days=90 : Keep this many days of tallies}';

    #[\Override]
    protected $description = 'Remove contact button tallies older than the retention period, on every site.';

    public function handle(Tenancy $tenancy): int
    {
        $days = max(1, is_numeric($this->option('days')) ? (int) $this->option('days') : 90);
        $cutoff = now()->subDays($days)->toDateString();
        $deleted = 0;
        $sites = 0;
        $failed = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            try {
                $removed = $tenancy->enter($siteId, fn (): mixed => ContactClickDaily::query()->where('day', '<', $cutoff)->delete());
                $deleted += is_int($removed) ? $removed : 0;
                $sites++;
            } catch (Throwable $e) {
                report($e);
                $this->error("Site {$siteId}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Removed {$deleted} contact click row(s) older than {$days} days on {$sites} site(s), {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
