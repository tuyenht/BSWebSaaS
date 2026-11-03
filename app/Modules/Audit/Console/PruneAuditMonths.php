<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Support\AuditMonths;
use Illuminate\Console\Command;

/**
 * Retention of the audit log: removes whole months older than the running month and the 24 full
 * months before it, one month per transaction, each with its `audit.months_pruned` row (month,
 * rows), so a failed audit row puts that month back. Nothing to remove means no change and no row; a rerun is
 * a no-op. Scheduled on the 1st of each month (routes/console.php).
 */
final class PruneAuditMonths extends Command
{
    public const string ACTION = 'audit.months_pruned';

    #[\Override]
    protected $signature = 'audit:prune';

    #[\Override]
    protected $description = 'Remove audit log months older than the 24 full months before the running one.';

    public function handle(AuditMonths $months): int
    {
        $expired = $months->expired();

        if ($expired === []) {
            $this->info('No audit log month is older than '.AuditMonths::KEEP_MONTHS.' full months.');

            return self::SUCCESS;
        }

        $rows = 0;
        foreach ($expired as $month) {
            $rows += $months->drop($month, self::ACTION);
        }

        $this->info('Removed '.count($expired).' audit log months ('.implode(', ', $expired).") with {$rows} rows.");

        return self::SUCCESS;
    }
}
