<?php

declare(strict_types=1);

namespace App\Modules\Monitoring;

use App\Modules\Monitoring\Jobs\SendErrorNotice;
use App\Modules\Monitoring\Support\ErrorCounter;
use App\Modules\Monitoring\Support\ErrorEvent;
use App\Modules\Monitoring\Support\EventCapture;
use App\Modules\Monitoring\Support\NoticeMailer;
use App\Modules\Monitoring\Support\ReportScope;
use Closure;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production error notices. Every fault on Laravel's report path — unhandled
 * errors, errors swallowed with report(), queued jobs that failed for good, scheduled commands that
 * failed — is counted by fingerprint in Valkey, and the operator is mailed on the first sighting in
 * 24 hours and when the day's count reaches each milestone, at most monitoring.hourly_ceiling
 * immediate mails an hour; the daily summary covers the rest.
 *
 * Containment comes first: nothing here may change a response, raise, report itself, or run SQL.
 * Every entry point is guarded; a failure inside is written to the log with a plain call and
 * dropped. The notice itself is sent by a queued job (Jobs\SendErrorNotice, on the `monitoring`
 * queue): a send waits on the mail host, and that wait must not hold a web worker. A dead Horizon
 * holds the notices back; the daily summary, sent by the scheduler, still covers them.
 */
final readonly class ErrorReporter
{
    public function __construct(
        private Application $app,
        private ReportScope $scope,
        private EventCapture $capture,
        private ErrorCounter $counter,
        private Dispatcher $bus,
    ) {}

    /** The exception handler's report hook (bootstrap/app.php): Laravel's ignore list already ran. */
    public function report(Throwable $e): void
    {
        $this->guarded(function () use ($e): void {
            if ($this->scope->wasCounted($e)) {
                return; // counted by its job-failed or task-failed event a moment ago
            }

            $this->scope->markCounted($e);
            $this->count($this->capture->fromThrowable($e, $this->scope->job(), $this->scope->command(), $this->scope->jobSiteId()));

            // A console command's own process: tell the scheduler watching its exit code that the
            // fault is already counted (it runs in another process — no shared memory).
            $command = $this->scope->command();
            if ($command !== null && $this->scope->job() === null && $this->app->runningInConsole()) {
                $this->counter->markCommandReported($command);
            }
        });
    }

    /**
     * The worker released a job to try it again after this exception. A job is counted once, when
     * it fails for good (jobFailed), so the attempt's exception — which the worker reports a moment
     * later — is marked as already handled. A fault that a later attempt gets past is what retries
     * are for, and is not a failure.
     */
    public function jobReleased(JobReleasedAfterException $event): void
    {
        $this->guarded(function () use ($event): void {
            if ($event->exception instanceof Throwable) {
                $this->scope->markCounted($event->exception);
            }
        });
    }

    /** Queue::failing — the job failed for good (no retries left). */
    public function jobFailed(JobFailed $event): void
    {
        $this->guarded(function () use ($event): void {
            $this->scope->markCounted($event->exception);
            $this->count($this->capture->forJob($event->exception, $event->job->resolveName(), self::siteOfJob($event->job)));
            $this->scope->setJob(null);
        });
    }

    /** The scheduler's task-failed event. */
    public function scheduledTaskFailed(ScheduledTaskFailed $event): void
    {
        $this->guarded(function () use ($event): void {
            $this->scope->markCounted($event->exception);
            $command = self::commandName($event->task);

            if ($this->counter->takeCommandReported($command)) {
                // Its own process already counted (and mailed) the exception it died of.
                $this->counter->recordCommandFailure($command, now()->getTimestamp());

                return;
            }

            $this->count($this->capture->forCommand($event->exception, $command), commandFailure: true);
        });
    }

    public function commandStarting(string $command): void
    {
        $this->scope->setCommand($command);
    }

    public function jobStarting(Job $job): void
    {
        $this->guarded(fn () => $this->scope->setJob($job->resolveName(), self::siteOfJob($job)));
    }

    /** A job ran to the end (a job run inline inside a request must not name the rest of it). */
    public function jobFinished(): void
    {
        $this->scope->setJob(null);
    }

    private function count(ErrorEvent $event, bool $commandFailure = false): void
    {
        ['first' => $first, 'count' => $count] = $this->counter->record($event, $commandFailure);

        // The notice job's own fault is counted (the summary lists it) but never mailed through
        // another notice job: that could only go round in a circle.
        if ($event->job === SendErrorNotice::class) {
            return;
        }

        $milestones = config('monitoring.milestones', []);
        $reason = match (true) {
            $first => 'first',
            is_array($milestones) && in_array($count, $milestones, true) => 'milestone',
            default => null,
        };

        if ($reason === null || ! $this->counter->claimMailSlot($event->occurredAt)) {
            return;
        }

        $this->dispatchNotice($event, $reason, $count);
    }

    /**
     * Hand the notice to the `monitoring` queue on Valkey — one push, no SQL — with only the
     * event's allow-listed fields. A failure to push is logged and dropped, never raised.
     */
    private function dispatchNotice(ErrorEvent $event, string $reason, int $count): void
    {
        try {
            $this->bus->dispatch(new SendErrorNotice($event->toArray(), $reason, $count));
        } catch (Throwable $e) {
            self::quietLog('notice not queued', $e);
        }
    }

    /** @param  Closure(): void  $work */
    private function guarded(Closure $work): void
    {
        try {
            if (! NoticeMailer::enabled() || ! $this->scope->enter()) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        try {
            $work();
        } catch (Throwable $e) {
            self::quietLog('report not counted', $e);
        } finally {
            $this->scope->leave();
        }
    }

    /**
     * The site a job runs for: its own `siteId` (or `tenantSiteId`) property, read straight
     * from the serialized command as a plain integer. Unserializing would restore models — SQL —
     * and nothing else of the payload is looked at. No such property (or an encrypted job) = none.
     */
    private static function siteOfJob(Job $job): ?int
    {
        $data = $job->payload()['data'] ?? null;
        $command = is_array($data) ? ($data['command'] ?? null) : null;

        if (is_string($command) && preg_match('/s:\d+:"(?:\x00[^\x00]*\x00)?(?:siteId|tenantSiteId)";i:(\d+);/', $command, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    /** The artisan command name of a scheduled task — never its arguments. */
    private static function commandName(ScheduledEvent $task): string
    {
        $command = is_string($task->command) ? $task->command : '';

        if (preg_match("/artisan'?\\s+'?([A-Za-z0-9:_.-]+)/", $command, $match) === 1) {
            return $match[1];
        }

        if (is_string($task->description) && $task->description !== '') {
            return $task->description;
        }

        return $command !== '' ? basename(trim((string) strtok($command, ' '), "'\"")) : 'callback';
    }

    private static function quietLog(string $what, Throwable $e): void
    {
        try {
            Log::warning('monitoring: '.$what, ['exception_class' => $e::class, 'error' => $e->getMessage()]);
        } catch (Throwable) {
            // the log itself is unavailable: nothing left to tell
        }
    }
}
