<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Exceptions\AuditMonthRefused;
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
        $removed = [];
        $left = [];
        foreach ($expired as $month) {
            try {
                $rows += $months->drop($month, self::ACTION);
                $removed[] = $month;
            } catch (AuditMonthRefused $e) {
                // removed a moment ago by someone else: nothing left to do; any other refusal (a
                // month the log stayed locked on) is left for the next run, the other months go on
                if ($e->reason !== 'missing') {
                    $left[] = $month;
                    $this->warn("Audit log month {$month} not removed ({$e->reason}); the next run tries again.");
                }
            }
        }

        $this->info('Removed '.count($removed).' audit log months ('.implode(', ', $removed).") with {$rows} rows.");

        return $left === [] ? self::SUCCESS : self::FAILURE;
    }
}
