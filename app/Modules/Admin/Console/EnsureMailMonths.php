<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Support\Mail\MailMonths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Creates the mail log's running month and the three after it where missing (a month it cannot lock
 * within 2s is left for the next run). Fails when fewer than two months remain ahead, so the
 * scheduler's failure notice reaches the operator well before mail rows would have nowhere to go
 * (a write into a missing month fails inside MailLogWriter's savepoint: the mail still leaves).
 * Idempotent; scheduled daily (routes/console.php).
 */
final class EnsureMailMonths extends Command
{
    #[\Override]
    protected $signature = 'mail:months';

    #[\Override]
    protected $description = 'Create the coming months of the mail log, and warn when too few are ready.';

    public function handle(MailMonths $months): int
    {
        $made = $months->ensure();
        $ahead = $months->monthsAhead();

        $this->info("Created {$made} mail log months; {$ahead} months are ready after the running one.");

        if ($ahead < MailMonths::WARN_AHEAD) {
            Log::warning('mail: fewer than '.MailMonths::WARN_AHEAD.' future months of the mail log exist', ['ahead' => $ahead]);
            $this->error("Only {$ahead} future months of the mail log exist: mail rows will fail once they run out.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
