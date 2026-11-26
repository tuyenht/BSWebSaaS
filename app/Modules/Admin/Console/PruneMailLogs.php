<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Support\Mail\MailMonths;
use App\Modules\Shared\Support\Cast;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Retention of the mail log (90 days): drops the months all of whose span is older than that, one
 * month per transaction, and removes the day counts older than that. A month the log stayed locked
 * on is left for the next run, the others go on. A rerun is a no-op. Scheduled daily
 * (routes/console.php).
 */
final class PruneMailLogs extends Command
{
    #[\Override]
    protected $signature = 'mail:prune';

    #[\Override]
    protected $description = 'Remove the mail log months and day counts older than 90 days.';

    public function handle(MailMonths $months): int
    {
        $removed = [];
        $left = [];
        foreach ($months->expired() as $month) {
            try {
                if ($months->drop($month)) {
                    $removed[] = $month;
                }
            } catch (QueryException $e) {
                $left[] = $month;
                $state = Cast::string($e->errorInfo[0] ?? null, '?');
                $this->warn("Mail log month {$month} not removed ({$state}); the next run tries again.");
            }
        }

        $days = $months->pruneDays();

        $this->info('Removed '.count($removed).' mail log months ('.implode(', ', $removed).") and {$days} day counts.");

        return $left === [] ? self::SUCCESS : self::FAILURE;
    }
}
