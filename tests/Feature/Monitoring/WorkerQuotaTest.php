<?php

declare(strict_types=1);

use App\Modules\Monitoring\Support\WorkerQuotaReader;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Hourly check of the Cloudflare Workers free allowance (100,000 requests per UTC day, whole
 * account): one mail per threshold per UTC day, quiet when off, never a crash.
 */

function workerQuotaReply(int ...$counts): array
{
    return ['data' => ['viewer' => ['accounts' => [[
        'workersInvocationsAdaptive' => array_map(fn (int $n): array => ['dimensions' => ['scriptName' => 'x'], 'sum' => ['requests' => $n]], $counts),
    ]]]], 'errors' => null];
}

/** What the faked Cloudflare answers next: a response, or the counts of a good answer. Http::fake keeps the first matching stub, so the stub reads this. */
function workerQuotaAnswer(mixed $answer): void
{
    $GLOBALS['workerQuotaAnswer'] = $answer;
}

function fakeWorkerQuota(int ...$counts): void
{
    workerQuotaAnswer(Http::response(workerQuotaReply(...$counts)));
}

function failWorkerQuota(mixed $body = 'boom', int $status = 500): void
{
    workerQuotaAnswer(Http::response($body, $status));
}

beforeEach(function (): void {
    $this->monitoringPrefix = enableMonitoring();
    fakeWorkerQuota(0);
    Http::fake(['api.cloudflare.com/*' => fn () => $GLOBALS['workerQuotaAnswer']]);
    config([
        'services.cloudflare.api_token' => 'test-token',
        'services.cloudflare.account_id' => str_repeat('a', 32),
        'monitoring.worker_quota.daily_limit' => 100_000,
        'monitoring.worker_quota.thresholds' => '50,80,100',
    ]);
    $this->travelTo('2026-11-14 10:00:00');
    $this->before = count(monitoringMails());
});

afterEach(function (): void {
    forgetMonitoringKeys($this->monitoringPrefix);
});

function workerQuotaMails(int $before): array
{
    return array_slice(monitoringMails(), $before);
}

it('is scheduled hourly', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'monitoring:worker-quota'));

    expect($event)->not->toBeNull()->and($event?->expression)->toBe('0 * * * *');
});

it('sends nothing below 50 percent', function (): void {
    fakeWorkerQuota(30_000, 19_000);

    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    expect(workerQuotaMails($this->before))->toBe([]);
});

it('mails once each when 50, then 80, then 100 percent are crossed, with plain Vietnamese numbers', function (): void {
    foreach ([[50_000], [60_000, 20_000], [100_000]] as $counts) {
        fakeWorkerQuota(...$counts);
        $this->artisan('monitoring:worker-quota')->assertSuccessful();
    }

    $mails = workerQuotaMails($this->before);
    expect($mails)->toHaveCount(3);

    $first = (string) $mails[0]->getSubject()."\n".$mails[0]->getTextBody();
    $last = (string) $mails[2]->getSubject()."\n".$mails[2]->getTextBody();

    expect($first)->toContain('50.000')->toContain('100.000')->toContain('50%')->toContain('07:00')
        ->and((string) $mails[1]->getSubject())->toContain('80%')
        ->and($last)->toContain('không tải được')->toContain('07:00');
});

it('exits 0 and tries again next hour when the mail server refuses the warning', function (): void {
    fakeWorkerQuota(85_000);
    config(['mail.mailers.monitoring.transport' => 'smtp', 'mail.mailers.monitoring.host' => '127.0.0.1', 'mail.mailers.monitoring.port' => 1, 'mail.mailers.monitoring.timeout' => 2]);
    Mail::purge('monitoring');

    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    config(['mail.mailers.monitoring.transport' => 'array']);
    Mail::purge('monitoring');
    $before = count(monitoringMails());
    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    $mails = array_slice(monitoringMails(), $before);
    expect($mails)->toHaveCount(1)->and((string) $mails[0]->getSubject())->toContain('85%');
});

it('does not repeat a mail on the same day', function (): void {
    fakeWorkerQuota(55_000);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();
    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    expect(workerQuotaMails($this->before))->toHaveCount(1);
});

it('sends only the highest threshold after a jump from 30 to 90 percent, and nothing lower later', function (): void {
    fakeWorkerQuota(30_000);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();
    fakeWorkerQuota(90_000);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();
    fakeWorkerQuota(91_000);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    $mails = workerQuotaMails($this->before);
    expect($mails)->toHaveCount(1)->and((string) $mails[0]->getSubject())->toContain('90%');
});

it('starts over on a new UTC day', function (): void {
    fakeWorkerQuota(60_000);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();
    $this->travelTo('2026-11-15 00:30:00');
    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    expect(workerQuotaMails($this->before))->toHaveCount(2);
    Http::assertSent(fn ($request): bool => ($request['variables']['d'] ?? null) === '2026-11-15');
});

it('logs a warning, sends nothing and exits 0 when the API fails or answers with an unexpected shape', function (): void {
    failWorkerQuota(['errors' => [['message' => 'nope']]], 200);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    failWorkerQuota('boom', 500);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    failWorkerQuota(['data' => ['viewer' => []]], 200);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    expect(workerQuotaMails($this->before))->toBe([]);
});

it('sends one "cannot read" mail per day after 6 failed hours in a row', function (): void {
    failWorkerQuota('boom', 500);

    foreach (range(1, 8) as $hour) {
        $this->artisan('monitoring:worker-quota')->assertSuccessful();
    }

    $mails = workerQuotaMails($this->before);
    expect($mails)->toHaveCount(1)->and((string) $mails[0]->getSubject())->toContain('Không đọc được');
});

it('counts failures in a row only: a good hour resets them', function (): void {
    failWorkerQuota('boom', 500);
    foreach (range(1, 5) as $hour) {
        $this->artisan('monitoring:worker-quota')->assertSuccessful();
    }
    fakeWorkerQuota(1_000);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();
    failWorkerQuota('boom', 500);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    expect(workerQuotaMails($this->before))->toBe([]);
});

it('does nothing when the notice mailer is off or the token or account id is missing', function (): void {
    fakeWorkerQuota(100_000);

    config(['monitoring.to' => null]);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    config(['monitoring.to' => 'owner@example.test', 'services.cloudflare.api_token' => null]);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    config(['services.cloudflare.api_token' => 'test-token', 'services.cloudflare.account_id' => null, 'filesystems.disks.r2.endpoint' => null]);
    $this->artisan('monitoring:worker-quota')->assertSuccessful();

    Http::assertNothingSent();
    expect(workerQuotaMails($this->before))->toBe([]);
});

it('takes the account id from CLOUDFLARE_ACCOUNT_ID, else from the R2 endpoint', function (): void {
    $id = str_repeat('0123456789abcdef', 2);

    config(['services.cloudflare.account_id' => null, 'filesystems.disks.r2.endpoint' => "https://{$id}.r2.cloudflarestorage.com"]);
    expect(WorkerQuotaReader::accountId())->toBe($id);

    config(['filesystems.disks.r2.endpoint' => 'https://example.com']);
    expect(WorkerQuotaReader::accountId())->toBe('');

    config(['services.cloudflare.account_id' => 'explicit']);
    expect(WorkerQuotaReader::accountId())->toBe('explicit');
});
