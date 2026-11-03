<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Support\AuditMonths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Creates the audit log's running month and the three after it where missing (a month it cannot
 * lock within 2s is left for the next run). Fails when fewer than two months remain ahead, so the
 * scheduler's failure notice reaches the operator in the morning summary well before audit rows
 * would have nowhere to go. Idempotent; scheduled daily (routes/console.php).
 */
final class EnsureAuditMonths extends Command
{
    #[\Override]
    protected $signature = 'audit:months';

    #[\Override]
    protected $description = 'Create the coming months of the audit log, and warn when too few are ready.';

    public function handle(AuditMonths $months): int
    {
        $made = $months->ensure();
        $ahead = $months->monthsAhead();

        $this->info("Created {$made} audit log months; {$ahead} months are ready after the running one.");

        if ($ahead < AuditMonths::WARN_AHEAD) {
            Log::warning('audit: fewer than '.AuditMonths::WARN_AHEAD.' future months of the audit log exist', ['ahead' => $ahead]);
            $this->error("Only {$ahead} future months of the audit log exist: audit rows will fail once they run out.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
