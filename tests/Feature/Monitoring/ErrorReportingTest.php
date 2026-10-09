<?php

declare(strict_types=1);

use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Monitoring\ErrorReporter;
use App\Modules\Monitoring\Support\DayWindow;
use App\Modules\Monitoring\Support\ErrorCounter;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Production error notices: every error that reaches Laravel's report path is counted in Valkey by
 * fingerprint (class + first application frame, never the message) and the operator is mailed on
 * the first sighting in 24 hours and when the day's count reaches 100 and 1,000 — at most 5
 * immediate mails an hour, on the dedicated `monitoring` mailer only. Off when no recipient or
 * no sender is configured. The report path never runs SQL and never lets its own failure through.
 */

/** Every call builds its exception on this same line: one fingerprint, whatever the message. */
function monitoringSameSpot(string $message): RuntimeException
{
    return new RuntimeException($message);
}

function monitoringFlush(): void
{
    app(ErrorReporter::class)->flush();
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
    monitoringFlush();

    expect(monitoringMails())->toHaveCount(2)
        ->and(array_values(monitoringCounts()))->toEqualCanonicalizing([3, 1]);
});

it('mails the first sighting once and remembers it for 24 hours', function (): void {
    report(monitoringSameSpot('a'));
    report(monitoringSameSpot('b'));
    report(monitoringSameSpot('c'));
    monitoringFlush();

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
    monitoringFlush();

    expect(monitoringMails())->toHaveCount(5)
        ->and(monitoringCounts())->toHaveCount(7);

    $this->travel(1)->hours();
    report(new OverflowException('next hour'));
    monitoringFlush();

    expect(monitoringMails())->toHaveCount(6);
});

it('mails again when the day count reaches 100 and 1,000', function (): void {
    for ($i = 1; $i <= 1_000; $i++) {
        report(monitoringSameSpot("hit {$i}"));
    }
    monitoringFlush();

    $subjects = array_map(fn ($mail): string => (string) $mail->getSubject(), monitoringMails());

    expect($subjects)->toHaveCount(3)
        ->and($subjects[1])->toContain('100')
        ->and($subjects[2])->toContain('1.000')
        ->and(array_values(monitoringCounts()))->toBe([1_000]);
});

it('does nothing beyond the normal log when no recipient is set', function (): void {
    config(['monitoring.to' => '']);

    report(new RuntimeException('quiet'));
    monitoringFlush();

    expect(monitoringMails())->toBe([])
        ->and(Redis::keys($this->monitoringPrefix.'*'))->toBe([]);
});

it('sends nothing — not even on the default mailer — when the monitoring sender is not configured', function (): void {
    config(['mail.mailers.monitoring.host' => null]);
    Mail::purge('monitoring');

    report(new RuntimeException('no sender'));
    monitoringFlush();

    expect(monitoringMails())->toBe([])
        ->and(monitoringMails('array'))->toBe([]);
});

it('sends on the monitoring mailer only, with its own sender and reply-to, in Vietnamese', function (): void {
    app()->setLocale('en');
    config(['mail.from' => ['address' => 'customer-shop@example.test', 'name' => 'Customer shop']]);

    report(new RuntimeException('boom'));
    monitoringFlush();

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
        monitoringFlush();
    });

    $replyTo = monitoringMails()[0]->getReplyTo();

    expect($replyTo)->toHaveCount(1)
        ->and($replyTo[0]->getAddress())->toBe('alerts@platform.example.test');
});

it('keeps Laravel\'s ignore list: not-found and validation errors are not faults', function (): void {
    report(new NotFoundHttpException);
    report(ValidationException::withMessages(['email' => 'invalid']));
    monitoringFlush();

    expect(monitoringMails())->toBe([])
        ->and(monitoringCounts())->toBe([]);
});

it('runs no SQL while reporting, even with a signed-in user and a current site', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    app(CurrentSite::class)->setId(1);

    $queries = countQueries(fn () => report(new RuntimeException('no sql here')));
    app(CurrentSite::class)->clear();
    $sendQueries = countQueries(fn () => monitoringFlush());

    expect($queries)->toBe(0)
        ->and($sendQueries)->toBe(0)
        ->and((string) monitoringMails()[0]->getTextBody())->toContain((string) $user->getKey())
        ->and((string) monitoringMails()[0]->getTextBody())->not->toContain($user->email);
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
