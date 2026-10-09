<?php

declare(strict_types=1);

use App\Modules\Monitoring\Jobs\SendErrorNotice;
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
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/*
 * A queued job that failed for good and a scheduled command that failed both reach the operator,
 * named by job class or command name only (no arguments, payload or output). A failure that both
 * raised an exception and ended the job or command counts ONCE — in the same process (the worker
 * reports the exception right after the job-failed event) and across processes (the scheduler runs
 * each command in a child process that reports its own exception before the parent sees the exit
 * code). A job allowed several attempts is counted once, when its last attempt fails, under its
 * own site; a fault reported while any attempt runs names that site too.
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

/** A job allowed three attempts that fails every one, outside any site context. */
final class MonitoringProbeRetriedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $siteId, public bool $reportFirst = false) {}

    public function handle(): void
    {
        if ($this->reportFirst) {
            report(new LogicException('a fault the job swallows and reports'));
        }

        throw new RuntimeException('attempt failed');
    }
}

/** A real Valkey queue of its own, so a released job really comes back for its next attempt. */
function monitoringRetryQueue(): RedisQueue
{
    $queue = new RedisQueue(app('redis'), 'monitoring-probe-'.Str::lower(Str::random(12)), 'default');
    $queue->setContainer(app());
    $queue->setConnectionName('redis');

    return $queue;
}

function forgetMonitoringRetryQueue(RedisQueue $queue): void
{
    $name = $queue->getQueue(null);

    Redis::connection('default')->del([$name, $name.':delayed', $name.':reserved', $name.':notify']);
}

/**
 * One attempt as the queue worker runs it: drop the scoped state (the worker resets it before each
 * job), take the job off the queue, process it, and report what it threw (Worker::runJob).
 *
 * @return int the attempt number
 */
function monitoringWorkerAttempt(RedisQueue $queue): int
{
    app()->forgetScopedInstances();
    $job = $queue->pop();

    if ($job === null) {
        throw new LogicException('the job did not come back for another attempt');
    }

    try {
        app('queue.worker')->process('redis', $job, new WorkerOptions(backoff: 0));
    } catch (Throwable $e) {
        report($e);
    }

    return $job->attempts();
}

/** @return list<array<string, int|string|null>> the events of the notices queued so far */
function monitoringQueuedEvents(): array
{
    return array_map(fn (SendErrorNotice $job): array => $job->event, queuedMonitoringNotices());
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
    sendMonitoringNotices();

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

it('counts a job that fails all its attempts once, after the last one, with the site it ran for', function (): void {
    $queue = monitoringRetryQueue();
    $queue->push(new MonitoringProbeRetriedJob(2));

    try {
        $attempts = [monitoringWorkerAttempt($queue), monitoringWorkerAttempt($queue)];
        $countsBeforeLast = monitoringToday()['counts'];
        $noticesBeforeLast = queuedMonitoringNotices();
        $attempts[] = monitoringWorkerAttempt($queue);
        $left = $queue->size();
    } finally {
        forgetMonitoringRetryQueue($queue);
    }

    $events = monitoringQueuedEvents();
    sendMonitoringNotices();
    $mails = monitoringMails();

    expect($attempts)->toBe([1, 2, 3])
        ->and($left)->toBe(0)
        ->and($countsBeforeLast)->toBe([])
        ->and($noticesBeforeLast)->toBe([])
        ->and(array_sum(monitoringToday()['counts']))->toBe(1)
        ->and($events)->toHaveCount(1)
        ->and($events[0]['job'])->toBe(MonitoringProbeRetriedJob::class)
        ->and($events[0]['siteId'])->toBe(2)
        ->and($mails)->toHaveCount(1)
        ->and((string) $mails[0]->getTextBody())->toContain('#2')
        ->not->toContain(__('monitoring.mail.site_platform', [], 'vi'));
});

it('names the job\'s own site for a fault reported during an early attempt', function (): void {
    $queue = monitoringRetryQueue();
    $queue->push(new MonitoringProbeRetriedJob(2, reportFirst: true));

    try {
        $attempt = monitoringWorkerAttempt($queue);
    } finally {
        forgetMonitoringRetryQueue($queue);
    }

    $events = monitoringQueuedEvents();

    // Only the swallowed fault counts; the attempt's own exception waits for the job's last attempt.
    expect($attempt)->toBe(1)
        ->and(array_sum(monitoringToday()['counts']))->toBe(1)
        ->and($events)->toHaveCount(1)
        ->and($events[0]['exception'])->toBe(LogicException::class)
        ->and($events[0]['job'])->toBe(MonitoringProbeRetriedJob::class)
        ->and($events[0]['siteId'])->toBe(2);
});

it('reports a failed scheduled command once, by name, without its arguments', function (): void {
    $task = monitoringScheduledTask("'/usr/local/bin/php' 'artisan' entries:publish-due --site=7 --token=SECRET42");
    $e = new Exception('Scheduled command ['.$task->command.'] failed with exit code [1].');

    // What schedule:run does when a command fails: the event, then the handler's report.
    event(new ScheduledTaskFailed($task, $e));
    report($e);
    sendMonitoringNotices();

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
    sendMonitoringNotices();

    // The parent (schedule:run) sees the non-zero exit code a moment later, in another process.
    app()->forgetScopedInstances();
    $task = monitoringScheduledTask("'/usr/local/bin/php' 'artisan' entries:publish-due");
    $e = new Exception('Scheduled command failed with exit code [1].');
    event(new ScheduledTaskFailed($task, $e));
    report($e);
    sendMonitoringNotices();

    $mails = monitoringMails();

    expect($mails)->toHaveCount(1)
        ->and((string) $mails[0]->getTextBody())->toContain('entries:publish-due')
        ->and(array_sum(monitoringToday()['counts']))->toBe(1)
        ->and(monitoringToday()['commands'])->toBe(['entries:publish-due' => 1]);
});
