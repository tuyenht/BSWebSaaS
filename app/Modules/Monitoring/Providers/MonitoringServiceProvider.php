<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Providers;

use App\Modules\Monitoring\Console\PingHeartbeat;
use App\Modules\Monitoring\Console\SendDailySummary;
use App\Modules\Monitoring\ErrorReporter;
use App\Modules\Monitoring\Support\ReportScope;
use Closure;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Production error notices. The exception handler's report hook lives in bootstrap/app.php; this
 * provider feeds the same reporter the background faults — a queued job that failed for good, a
 * scheduled command that failed — and tells it which job or command is running. Depends on Shared
 * and Tenancy\Context only; no module depends on it (tests/Arch/ModuleBoundariesTest.php).
 */
final class MonitoringServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // Scoped: Octane forgets it between requests and the queue worker between jobs.
        $this->app->scoped(ReportScope::class);
    }

    public function boot(): void
    {
        Queue::failing(fn (JobFailed $event) => self::reporter(fn (ErrorReporter $reporter) => $reporter->jobFailed($event)));

        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => self::reporter(
            fn (ErrorReporter $reporter) => $reporter->scheduledTaskFailed($event),
        ));

        Event::listen(CommandStarting::class, fn (CommandStarting $event) => self::reporter(
            fn (ErrorReporter $reporter) => $reporter->commandStarting((string) $event->command),
        ));

        Event::listen(JobProcessing::class, fn (JobProcessing $event) => self::reporter(
            fn (ErrorReporter $reporter) => $reporter->jobStarting($event->job),
        ));

        Event::listen(JobProcessed::class, fn () => self::reporter(fn (ErrorReporter $reporter) => $reporter->jobFinished()));

        // A queue worker sends a job's notices before it takes the next job. The listener must
        // return nothing: `false` from a Looping listener pauses the worker.
        Event::listen(Looping::class, function (): void {
            self::reporter(fn (ErrorReporter $reporter) => $reporter->flush());
        });

        if ($this->app->runningInConsole()) {
            $this->commands([SendDailySummary::class, PingHeartbeat::class]);
        }
    }

    /**
     * Run against the reporter of the CURRENT container (Octane's per-request sandbox), and let
     * nothing escape: these listeners sit on every job and command.
     *
     * @param  Closure(ErrorReporter): void  $call
     */
    private static function reporter(Closure $call): void
    {
        try {
            $call(app(ErrorReporter::class));
        } catch (Throwable) {
            // the reporter guards itself; this only catches a failure to build it
        }
    }
}
