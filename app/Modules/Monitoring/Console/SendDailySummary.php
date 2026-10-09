<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Console;

use App\Modules\Monitoring\Support\DayWindow;
use App\Modules\Monitoring\Support\ErrorCounter;
use App\Modules\Monitoring\Support\ErrorEvent;
use App\Modules\Monitoring\Support\NoticeMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The morning summary of the counting day that just closed (DayWindow): each fault with its count
 * and the sites it hit, the failed jobs still waiting per job class, the scheduled commands that
 * failed. Sent only when there is something to say; a fault lasting days so shows up every
 * morning. Scheduled at monitoring.summary_at in monitoring.timezone (routes/console.php).
 */
final class SendDailySummary extends Command
{
    #[\Override]
    protected $signature = 'monitoring:summary';

    #[\Override]
    protected $description = 'Mail the operator a summary of yesterday\'s faults (only when there were any)';

    public function handle(ErrorCounter $counter, DayWindow $days, NoticeMailer $mailer): int
    {
        if (! NoticeMailer::enabled()) {
            return self::SUCCESS;
        }

        $id = $days->previous(now());
        $window = $counter->window($id);
        $failedJobs = $this->failedJobsByClass();

        if ($window['counts'] === [] && $window['commands'] === [] && $failedJobs === []) {
            return self::SUCCESS;
        }

        $start = $days->startOf($id);
        $lines = [$mailer->t('monitoring.mail.summary.intro', [
            'from' => $start->format('H:i d/m/Y'),
            'to' => $start->addDay()->format('H:i d/m/Y'),
        ])];

        if ($window['counts'] !== []) {
            $lines[] = '';
            $lines[] = $mailer->t('monitoring.mail.summary.errors');
            $limit = config('monitoring.summary_limit', 50);
            $limit = is_int($limit) ? $limit : 50;

            foreach (array_slice($window['counts'], 0, $limit, true) as $fingerprint => $count) {
                $lines[] = '- '.$mailer->t('monitoring.mail.summary.error_line', [
                    'count' => $mailer->number($count),
                    'sites' => $mailer->number(count(array_filter($window['sites'][$fingerprint] ?? [], ctype_digit(...)))),
                    'what' => $this->describe($window['samples'][$fingerprint] ?? null, $mailer),
                ]);
            }

            if (count($window['counts']) > $limit) {
                $lines[] = $mailer->t('monitoring.mail.summary.more', ['count' => $mailer->number(count($window['counts']) - $limit)]);
            }
        }

        if ($failedJobs !== []) {
            $lines[] = '';
            $lines[] = $mailer->t('monitoring.mail.summary.failed_jobs');

            foreach ($failedJobs as $job => $count) {
                $lines[] = '- '.$mailer->t('monitoring.mail.summary.failed_job_line', ['job' => $job, 'count' => $mailer->number($count)]);
            }
        }

        if ($window['commands'] !== []) {
            $lines[] = '';
            $lines[] = $mailer->t('monitoring.mail.summary.commands');

            foreach ($window['commands'] as $command => $count) {
                $lines[] = '- '.$mailer->t('monitoring.mail.summary.command_line', ['command' => $command, 'count' => $mailer->number($count)]);
            }
        }

        $mailer->sendSummary($mailer->t('monitoring.mail.summary.subject', ['date' => $start->format('d/m/Y')]), implode("\n", $lines));

        return self::SUCCESS;
    }

    /** What the fault was, from its sample: where it happened, its class and the line of ours. */
    private function describe(mixed $sample, NoticeMailer $mailer): string
    {
        if (! $sample instanceof ErrorEvent) {
            return '?';
        }

        $parts = array_filter([
            $sample->job ?? $sample->command ?? $sample->routeName ?? $sample->routePattern,
            $sample->exception,
            $sample->sqlState,
            $sample->location,
        ], fn (?string $part): bool => $part !== null && $part !== '');

        $what = implode(' · ', $parts);

        return $sample->siteId === null ? $what.' · '.$mailer->t('monitoring.mail.site_platform') : $what;
    }

    /**
     * Failed jobs waiting in the failed-jobs table, counted per job class. Only the class name is
     * read out of the payload; the payload and the exception column never leave the database.
     *
     * @return array<string, int>
     */
    private function failedJobsByClass(): array
    {
        try {
            $table = config('queue.failed.table', 'failed_jobs');
            $connection = config('queue.failed.database');

            $rows = DB::connection(is_string($connection) ? $connection : null)
                ->table(is_string($table) ? $table : 'failed_jobs')
                ->selectRaw("payload::json->>'displayName' as job, count(*) as total")
                ->groupByRaw("payload::json->>'displayName'")
                ->orderByDesc('total')
                ->limit(50)
                ->get();
        } catch (Throwable $e) {
            Log::warning('monitoring: failed jobs not read for the summary', ['exception_class' => $e::class]);

            return [];
        }

        $jobs = [];
        foreach ($rows as $row) {
            $job = isset($row->job) && is_string($row->job) ? $row->job : '?';
            $total = isset($row->total) && is_numeric($row->total) ? (int) $row->total : 0;
            $jobs[$job] = ($jobs[$job] ?? 0) + $total;
        }

        return $jobs;
    }
}
