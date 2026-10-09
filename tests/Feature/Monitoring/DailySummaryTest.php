<?php

declare(strict_types=1);

use App\Modules\Monitoring\Support\DayWindow;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Every morning at 08:00 Vietnam time the operator gets one summary of the counting day that just
 * closed (08:00 to 08:00 — the same day the counters use): each fault with its count and the number
 * of sites it hit, the failed jobs still waiting per job class, and the scheduled commands that
 * failed. Nothing to say = no mail. A data-free heartbeat ping every 5 minutes, only when its
 * address is configured, proves from outside that the scheduler is alive.
 */

function monitoringSummaryFault(): RuntimeException
{
    return new RuntimeException('summary fault for khach@example.com');
}

function monitoringScheduled(string $needle): ?ScheduledEvent
{
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, $needle)) {
            return $event;
        }
    }

    return null;
}

beforeEach(function (): void {
    $this->monitoringPrefix = enableMonitoring();
    DB::connection('pgsql_admin')->table('failed_jobs')->delete();
});

afterEach(function (): void {
    forgetMonitoringKeys($this->monitoringPrefix);
    DB::connection('pgsql_admin')->table('failed_jobs')->delete();
});

it('is scheduled at 08:00 Vietnam time while the app clock stays UTC', function (): void {
    $event = monitoringScheduled('monitoring:summary');

    expect(config('app.timezone'))->toBe('UTC')
        ->and($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 8 * * *')
        ->and($event?->timezone)->toBe('Asia/Ho_Chi_Minh');
});

it('counts the day from 08:00 to 08:00 Vietnam time', function (): void {
    $window = app(DayWindow::class);

    expect($window->id(Carbon::parse('2026-10-10 00:59:59', 'UTC')))->toBe('20261009')  // 07:59:59 in Vietnam
        ->and($window->id(Carbon::parse('2026-10-10 01:00:00', 'UTC')))->toBe('20261010') // 08:00:00 in Vietnam
        ->and($window->previous(Carbon::parse('2026-10-10 01:00:30', 'UTC')))->toBe('20261009');
});

it('sums up the day that just closed: faults, sites hit, failed jobs and failed commands', function (): void {
    $this->travelTo(Carbon::parse('2026-10-09 05:00:00', 'UTC')); // 12:00 in Vietnam

    app(CurrentSite::class)->setId(1);
    report(monitoringSummaryFault());
    report(monitoringSummaryFault());
    app(CurrentSite::class)->setId(2);
    report(monitoringSummaryFault());
    app(CurrentSite::class)->clear();

    $task = new ScheduledEvent(app(CacheEventMutex::class), "'php' 'artisan' domains:recheck");
    event(new ScheduledTaskFailed($task, $e = new Exception('exit code 1')));
    report($e);

    DB::connection('pgsql_admin')->table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendInvoice', 'data' => ['command' => 'O:8:"secret";khach@example.com']]),
        'exception' => 'RuntimeException: card 4111111111111111 declined for khach@example.com',
        'failed_at' => now(),
    ]);

    $this->travelTo(Carbon::parse('2026-10-10 01:00:30', 'UTC')); // 08:00:30 in Vietnam
    $before = count(monitoringMails());
    $this->artisan('monitoring:summary')->assertSuccessful();

    $mails = array_slice(monitoringMails(), $before);
    expect($mails)->toHaveCount(1);
    $text = (string) $mails[0]->getSubject()."\n".$mails[0]->getTextBody();

    expect((string) $mails[0]->getSubject())->toContain('09/10/2026')
        ->and($text)->toContain('3 lần')
        ->toContain('2 website')
        ->toContain('RuntimeException')
        ->toContain('App\\Jobs\\SendInvoice')
        ->toContain('domains:recheck')
        ->not->toContain('khach@example.com')
        ->not->toContain('4111111111111111')
        ->not->toContain('secret');
});

it('sends no summary when the day was quiet', function (): void {
    $this->travelTo(Carbon::parse('2026-10-10 01:00:30', 'UTC'));

    $this->artisan('monitoring:summary')->assertSuccessful();

    expect(monitoringMails())->toBe([]);
});

it('pings the heartbeat with a bare GET every 5 minutes, only when its address is set', function (): void {
    Http::fake();
    $event = monitoringScheduled('monitoring:heartbeat');

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('*/5 * * * *')
        ->and($event?->filtersPass(app()))->toBeFalse();

    $this->artisan('monitoring:heartbeat')->assertSuccessful();
    Http::assertNothingSent();

    config(['monitoring.heartbeat_url' => 'https://hc.example.test/ping/abc']);

    expect($event?->filtersPass(app()))->toBeTrue();
    $this->artisan('monitoring:heartbeat')->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://hc.example.test/ping/abc'
        && $request->method() === 'GET'
        && $request->body() === '');
    Http::assertSentCount(1);
});
