<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Support\AuditMonths;
use Illuminate\Console\Command;

/**
 * Retention of the audit log: removes whole months older than the running month and the 24 full
 * months before it, and records `audit.months_pruned` (months, rows) in the same transaction, so a
 * failed audit row puts the months back. Nothing to remove means no change and no row; a rerun is
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

        $rows = $months->drop($expired, self::ACTION, ['meta' => ['months' => $expired]]);

        $this->info('Removed '.count($expired).' audit log months ('.implode(', ', $expired).") with {$rows} rows.");

        return self::SUCCESS;
    }
}
