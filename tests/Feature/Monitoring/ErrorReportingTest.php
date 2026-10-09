<?php

declare(strict_types=1);

use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Monitoring\Jobs\SendErrorNotice;
use App\Modules\Monitoring\Support\DayWindow;
use App\Modules\Monitoring\Support\ErrorCounter;
use App\Modules\Monitoring\Support\ErrorEvent;
use App\Modules\Monitoring\Support\ReportScope;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Production error notices: every error that reaches Laravel's report path is counted in Valkey by
 * fingerprint (class + first application frame, never the message) and the operator is mailed on
 * the first sighting in 24 hours and when the day's count reaches 100 and 1,000 — at most 5
 * immediate mails an hour, on the dedicated `monitoring` mailer only. Off when no recipient or
 * no sender is configured. The report path never runs SQL and never lets its own failure through;
 * it only queues a notice job (on the `monitoring` queue), whose worker does the sending.
 */

/** Every call builds its exception on this same line: one fingerprint, whatever the message. */
function monitoringSameSpot(string $message): RuntimeException
{
    return new RuntimeException($message);
}

/** @return array<string, int> fingerprint => count for the current day */
function monitoringCounts(): array
{
    return app(ErrorCounter::class)->window(app(DayWindow::class)->id(now()))['counts'];
}

beforeEach(function (): void {
    $this->monitoringPrefix = enableMonitoring();
});

afterEach(function (): void {
    forgetMonitoringKeys($this->monitoringPrefix);
});

it('groups errors by where they happen, not by their message', function (): void {
    report(monitoringSameSpot('order 1 failed'));
    report(monitoringSameSpot('order 2 failed at 10:31'));
    report(monitoringSameSpot('order 3 failed for khach@example.com'));
    report(new RuntimeException('somewhere else'));
    sendMonitoringNotices();

    expect(monitoringMails())->toHaveCount(2)
        ->and(array_values(monitoringCounts()))->toEqualCanonicalizing([3, 1]);
});

it('mails the first sighting once and remembers it for 24 hours', function (): void {
    report(monitoringSameSpot('a'));
    report(monitoringSameSpot('b'));
    report(monitoringSameSpot('c'));
    sendMonitoringNotices();

    $fingerprint = array_key_first(monitoringCounts());
    $ttl = Redis::ttl($this->monitoringPrefix.'seen:'.$fingerprint);

    expect(monitoringMails())->toHaveCount(1)
        ->and($ttl)->toBeGreaterThan(86_300)->toBeLessThanOrEqual(86_400);
});

it('sends at most 5 immediate mails an hour and the rest wait for the summary', function (): void {
    $this->travelTo(now()->startOfHour()->addMinutes(5));
    $classes = [
        RuntimeException::class, LogicException::class, InvalidArgumentException::class, DomainException::class,
        LengthException::class, OutOfRangeException::class, UnexpectedValueException::class,
    ];

    foreach ($classes as $class) {
        report(new $class('distinct fault'));
    }
    sendMonitoringNotices();

    expect(monitoringMails())->toHaveCount(5)
        ->and(monitoringCounts())->toHaveCount(7);

    $this->travel(1)->hours();
    report(new OverflowException('next hour'));
    sendMonitoringNotices();

    expect(monitoringMails())->toHaveCount(6);
});

it('mails again when the day count reaches 100 and 1,000', function (): void {
    for ($i = 1; $i <= 1_000; $i++) {
        report(monitoringSameSpot("hit {$i}"));
    }
    sendMonitoringNotices();

    $subjects = array_map(fn ($mail): string => (string) $mail->getSubject(), monitoringMails());

    expect($subjects)->toHaveCount(3)
        ->and($subjects[1])->toContain('100')
        ->and($subjects[2])->toContain('1.000')
        ->and(array_values(monitoringCounts()))->toBe([1_000]);
});

it('does nothing beyond the normal log when no recipient is set', function (): void {
    config(['monitoring.to' => '']);

    report(new RuntimeException('quiet'));
    sendMonitoringNotices();

    expect(monitoringMails())->toBe([])
        ->and(Redis::keys($this->monitoringPrefix.'*'))->toBe([]);
});

it('sends nothing — not even on the default mailer — when the monitoring sender is not configured', function (): void {
    config(['mail.mailers.monitoring.host' => null]);
    Mail::purge('monitoring');

    report(new RuntimeException('no sender'));
    sendMonitoringNotices();

    expect(monitoringMails())->toBe([])
        ->and(monitoringMails('array'))->toBe([]);
});

it('sends on the monitoring mailer only, with its own sender and reply-to, in Vietnamese', function (): void {
    app()->setLocale('en');
    config(['mail.from' => ['address' => 'customer-shop@example.test', 'name' => 'Customer shop']]);

    report(new RuntimeException('boom'));
    sendMonitoringNotices();

    $mail = monitoringMails()[0];

    expect(monitoringMails('array'))->toBe([])
        ->and($mail->getFrom()[0]->getAddress())->toBe('alerts@platform.example.test')
        ->and($mail->getReplyTo()[0]->getAddress())->toBe('alerts@platform.example.test')
        ->and($mail->getTo()[0]->getAddress())->toBe('owner@example.test')
        ->and((string) $mail->getSubject())->toContain('gặp lỗi')
        ->and((string) $mail->getTextBody())->toContain('giờ Việt Nam');
});

it('keeps its own reply-to even when sent inside a site that set a customer reply-to', function (): void {
    seedTenancy();

    app(Tenancy::class)->enter(1, function (): void {
        app(SettingsRepository::class)->put('email', 'config', ['reply_to' => 'lienhe@customer.example.test']);
        report(new RuntimeException('inside a site'));
        sendMonitoringNotices();
    });

    $replyTo = monitoringMails()[0]->getReplyTo();

    expect($replyTo)->toHaveCount(1)
        ->and($replyTo[0]->getAddress())->toBe('alerts@platform.example.test');
});

it('keeps Laravel\'s ignore list: not-found and validation errors are not faults', function (): void {
    report(new NotFoundHttpException);
    report(ValidationException::withMessages(['email' => 'invalid']));
    sendMonitoringNotices();

    expect(monitoringMails())->toBe([])
        ->and(monitoringCounts())->toBe([]);
});

it('only queues the notice: one job on the monitoring queue on Valkey, no SQL, nothing sent yet', function (): void {
    $queries = countQueries(fn () => report(new RuntimeException('queued, not sent')));

    $jobs = queuedMonitoringNotices();

    expect($queries)->toBe(0)
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0]->connection)->toBe('redis')
        ->and($jobs[0]->queue)->toBe('monitoring')
        ->and($jobs[0]->afterCommit)->toBeFalse()
        ->and(config('queue.connections.redis.driver'))->toBe('redis')
        ->and(monitoringMails())->toBe([]);
    Queue::assertPushedOn('monitoring', SendErrorNotice::class);
    Queue::assertCount(1);
});

it('runs no SQL while reporting, even with a signed-in user and a current site', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    app(CurrentSite::class)->setId(1);

    $queries = countQueries(fn () => report(new RuntimeException('no sql here')));
    app(CurrentSite::class)->clear();
    $sendQueries = countQueries(fn () => sendMonitoringNotices());

    expect($queries)->toBe(0)
        ->and($sendQueries)->toBe(0)
        ->and((string) monitoringMails()[0]->getTextBody())->toContain((string) $user->getKey())
        ->and((string) monitoringMails()[0]->getTextBody())->not->toContain($user->email);
});

it('runs no SQL in the report hook of a real request, and never loads a signed-in user itself', function (): void {
    $user = User::factory()->create();
    $inHook = false;
    $hookQueries = 0;
    $hookRuns = 0;

    DB::listen(function () use (&$inHook, &$hookQueries): void {
        if ($inHook) {
            $hookQueries++;
        }
    });

    // Report callbacks run in the order they were added: this one runs right after the monitoring
    // hook and before Laravel's own log context (which reads the user), closing the window.
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$inHook, &$hookRuns): void {
        if ($inHook) {
            $hookRuns++;
        }
        $inHook = false;
    });

    // The window opens at the throw: what runs next, up to the callback above, is the report hook.
    Route::middleware('web')->get('/__monitoring-session-only', function () use (&$inHook): never {
        $inHook = true;
        throw new RuntimeException('the request never asked who is signed in');
    });
    Route::middleware('web')->get('/__monitoring-user-loaded', function () use (&$inHook): never {
        auth()->user();
        $inHook = true;
        throw new RuntimeException('the request loaded the signed-in user');
    });

    // The session holds the sign-in; the guard starts empty and only the request may load the user.
    $session = [Auth::guard('web')->getName() => $user->getKey()];
    Auth::forgetGuards();

    $sessionOnly = $this->withSession($session)->get('/__monitoring-session-only');
    $sessionOnlyQueries = $hookQueries;
    $sessionOnlyEvent = queuedMonitoringNotices()[0]->event;

    Auth::forgetGuards();
    $hookQueries = 0;
    $loaded = $this->withSession($session)->get('/__monitoring-user-loaded');
    $loadedEvent = queuedMonitoringNotices()[1]->event;

    expect($sessionOnly->getStatusCode())->toBe(500)
        ->and($loaded->getStatusCode())->toBe(500)
        ->and($hookRuns)->toBe(2)
        ->and($sessionOnlyQueries)->toBe(0)
        ->and($hookQueries)->toBe(0)
        ->and($sessionOnlyEvent['routePattern'])->toBe('__monitoring-session-only')
        ->and($sessionOnlyEvent['userId'])->toBeNull()
        ->and($loadedEvent['routePattern'])->toBe('__monitoring-user-loaded')
        ->and($loadedEvent['userId'])->toBe($user->getKey());
});

it('swallows its own failure: the response is unchanged and it does not report itself', function (): void {
    Route::get('/__monitoring-broken', fn () => throw new RuntimeException('page fault'));

    config(['monitoring.to' => '']);
    $quiet = $this->get('/__monitoring-broken');

    enableMonitoring();
    config(['database.redis.default.port' => 1, 'database.redis.default.max_retries' => 0]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstances();
    Log::spy();

    $broken = $this->get('/__monitoring-broken');

    expect($broken->getStatusCode())->toBe($quiet->getStatusCode())->toBe(500)
        ->and(monitoringMails())->toBe([]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'monitoring:'))->once();
});

it('changes no response and does not loop when the notice cannot be queued', function (): void {
    Route::get('/__monitoring-unqueued', fn () => throw new RuntimeException('page fault'));

    config(['monitoring.to' => '']);
    $quiet = $this->get('/__monitoring-unqueued');

    config(['monitoring.to' => 'owner@example.test']);
    Queue::fake([SendErrorNotice::class])->beforePushing(function (mixed $job): void {
        if ($job instanceof SendErrorNotice) {
            throw new RuntimeException('queue backend down');
        }
    });
    Log::spy();

    $response = $this->get('/__monitoring-unqueued');

    expect($response->getStatusCode())->toBe($quiet->getStatusCode())->toBe(500)
        ->and(array_values(monitoringCounts()))->toBe([1])
        ->and(queuedMonitoringNotices())->toBe([])
        ->and(monitoringMails())->toBe([]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'monitoring:'))->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'monitoring: notice not queued')->once();
});

it('counts a fault of the notice job itself but never queues a notice about it', function (): void {
    app(ReportScope::class)->setJob(SendErrorNotice::class);

    report(new RuntimeException('mail host gone'));

    expect(monitoringCounts())->toHaveCount(1)
        ->and(queuedMonitoringNotices())->toBe([]);
});

it('the notice job sends on the monitoring mailer only, and only logs a send that failed', function (): void {
    $event = new ErrorEvent(
        fingerprint: 'job-mailer-check',
        kind: ErrorEvent::KIND_OTHER,
        occurredAt: now()->getTimestamp(),
        environment: 'production',
        exception: RuntimeException::class,
        location: 'app/Example.php:12',
    );
    $job = new SendErrorNotice($event->toArray(), 'first', 1);

    app()->call([$job, 'handle']);

    expect(monitoringMails())->toHaveCount(1)
        ->and(monitoringMails('array'))->toBe([])
        ->and(monitoringMails()[0]->getFrom()[0]->getAddress())->toBe('alerts@platform.example.test');

    // The mail host refuses: the job logs it and ends normally (a failed notice job would report itself).
    config([
        'mail.mailers.monitoring.transport' => 'smtp',
        'mail.mailers.monitoring.host' => '127.0.0.1',
        'mail.mailers.monitoring.port' => 1,
        'mail.mailers.monitoring.timeout' => 1,
    ]);
    Mail::purge('monitoring');
    Log::spy();

    app()->call([$job, 'handle']);

    expect(monitoringMails('array'))->toBe([]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'monitoring: notice not sent')->once();
});
