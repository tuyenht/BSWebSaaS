<?php

declare(strict_types=1);

use App\Modules\Monitoring\ErrorReporter;
use App\Modules\Monitoring\Support\DayWindow;
use App\Modules\Monitoring\Support\ErrorCounter;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/*
 * A queued job that failed for good and a scheduled command that failed both reach the operator,
 * named by job class or command name only (no arguments, payload or output). A failure that both
 * raised an exception and ended the job or command counts ONCE — in the same process (the worker
 * reports the exception right after the job-failed event) and across processes (the scheduler runs
 * each command in a child process that reports its own exception before the parent sees the exit
 * code).
 */

final class MonitoringProbeFailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $siteId, public string $secret) {}

    public function handle(): void
    {
        throw new RuntimeException('job failed for '.$this->secret);
    }
}

/** @return array{counts: array<string, int>, commands: array<string, int>} */
function monitoringToday(): array
{
    $window = app(ErrorCounter::class)->window(app(DayWindow::class)->id(now()));

    return ['counts' => $window['counts'], 'commands' => $window['commands']];
}

function monitoringScheduledTask(string $command): ScheduledEvent
{
    return new ScheduledEvent(app(CacheEventMutex::class), $command);
}

beforeEach(function (): void {
    $this->monitoringPrefix = enableMonitoring();
});

afterEach(function (): void {
    forgetMonitoringKeys($this->monitoringPrefix);
});

it('reports a failed job once, by class, with the site it ran for', function (): void {
    try {
        app(Dispatcher::class)->dispatch(new MonitoringProbeFailingJob(2, 'khach@example.com'));
    } catch (RuntimeException $e) {
        report($e); // what the queue worker does right after the job-failed event
    }
    app(ErrorReporter::class)->flush();

    $mails = monitoringMails();
    $text = (string) $mails[0]->getSubject()."\n".$mails[0]->getTextBody();

    expect($mails)->toHaveCount(1)
        ->and(array_sum(monitoringToday()['counts']))->toBe(1)
        ->and($text)->toContain('MonitoringProbeFailingJob')
        ->toContain('RuntimeException')
        ->toContain('#2')
        ->toContain(__('monitoring.mail.doing.job', [], 'vi'))
        ->not->toContain('khach@example.com')
        ->not->toContain('job failed for');
});

it('reports a failed scheduled command once, by name, without its arguments', function (): void {
    $task = monitoringScheduledTask("'/usr/local/bin/php' 'artisan' entries:publish-due --site=7 --token=SECRET42");
    $e = new Exception('Scheduled command ['.$task->command.'] failed with exit code [1].');

    // What schedule:run does when a command fails: the event, then the handler's report.
    event(new ScheduledTaskFailed($task, $e));
    report($e);
    app(ErrorReporter::class)->flush();

    $mails = monitoringMails();
    $text = (string) $mails[0]->getSubject()."\n".$mails[0]->getTextBody();

    expect($mails)->toHaveCount(1)
        ->and(array_sum(monitoringToday()['counts']))->toBe(1)
        ->and(monitoringToday()['commands'])->toBe(['entries:publish-due' => 1])
        ->and($text)->toContain('entries:publish-due')
        ->not->toContain('SECRET42')
        ->not->toContain('--site')
        ->not->toContain('/usr/local/bin/php');
});

it('counts a command failure once when its own process already reported the exception', function (): void {
    // The child process: the command starts, throws, and its exception is reported there.
    event(new CommandStarting('entries:publish-due', new ArrayInput([]), new NullOutput));
    report(new RuntimeException('inside the command'));
    app(ErrorReporter::class)->flush();

    // The parent (schedule:run) sees the non-zero exit code a moment later, in another process.
    app()->forgetScopedInstances();
    $task = monitoringScheduledTask("'/usr/local/bin/php' 'artisan' entries:publish-due");
    $e = new Exception('Scheduled command failed with exit code [1].');
    event(new ScheduledTaskFailed($task, $e));
    report($e);
    app(ErrorReporter::class)->flush();

    $mails = monitoringMails();

    expect($mails)->toHaveCount(1)
        ->and((string) $mails[0]->getTextBody())->toContain('entries:publish-due')
        ->and(array_sum(monitoringToday()['counts']))->toBe(1)
        ->and(monitoringToday()['commands'])->toBe(['entries:publish-due' => 1]);
});
