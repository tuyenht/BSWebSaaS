<?php

use App\Modules\Admin\Notifications\EmailOtpNotification;
use App\Modules\Admin\Notifications\MemberInvitationNotification;
use App\Modules\Admin\Support\Mail\MailKinds;
use App\Modules\Admin\Support\Mail\MailQuota;
use App\Modules\Admin\Support\Mail\MailQuotaGate;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Shared\Events\MailQuotaReached;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Jobs\WithinSite;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as Notifications;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
 * The daily limit of the platform's shared outbox (`mail.platform_daily`). Every website mail that
 * leaves through the shared outbox is counted once on the website's day; an ordinary mail (an
 * invitation, a domain notice) sent by its queued job takes its place before it leaves and, past
 * ten times the limit (or at once with a limit of 0) or while the platform's own ceiling is reached,
 * waits for the next day as the same queued mail. Security mails and contact-form notices always
 * leave, and mail through the website's own outbox is never limited.
 *
 * Each queued mail is caught by a queue fake and then run through its own job middleware, as the
 * worker does; the shared outbox's server is an array transport (nothing leaves the box).
 */

beforeEach(function (): void {
    config([
        'admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef'),
        'queue.default' => 'redis',
        'mail.quota.platform_daily_cap' => null,
    ]);
    seedTenancy(); // sites 1 / 2; the cascade empties mail_daily
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings, mail_logs restart identity cascade');
    Queue::fake([SendQueuedNotifications::class]);
    Event::fake([MailQuotaReached::class]);
    $this->mqTransport = new ArrayTransport;
    $transport = $this->mqTransport;
    Mail::extend('smtp', fn (): ArrayTransport => $transport);
    mqPlatformOutbox();
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings, mail_logs restart identity cascade');
});

function mqPlatformOutbox(): void
{
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'no-reply@nen-tang.test',
        'smtp' => ['host' => 'smtp.nen-tang.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'relay', 'password' => SecretCipher::encrypt('pw')],
    ]);
}

function mqOwnOutbox(): void
{
    app()->instance(MailHostGuard::class, new MailHostGuard(fn (string $host): array => ['93.184.216.34']));
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', [
        'provider' => 'other', 'method' => 'smtp_password', 'from_address' => 'info@anvinh.test',
        'smtp' => ['host' => 'smtp.anvinh.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'password' => SecretCipher::encrypt('own')],
    ]));
}

function mqInvite(int $site = 1): MemberInvitationNotification
{
    $note = new MemberInvitationNotification('https://a.test/moi/abc', 'A', 'Chủ website', [['name' => 'viewer', 'system' => true]], null, '30/11/2026');
    $note->tenantSiteId = $site;

    return $note;
}

function mqOtp(int $site = 1): EmailOtpNotification
{
    $note = new EmailOtpNotification('123456', 10);
    $note->tenantSiteId = $site;

    return $note;
}

/** Queues $note for $to as a website does, and returns the mail channel's job the fake caught. */
function mqQueue(Notification $note, string $to = 'nguoi-nhan@example.test'): SendQueuedNotifications
{
    Notifications::route('mail', $to)->notify($note);
    $job = Queue::pushed(SendQueuedNotifications::class)->last();
    expect($job)->toBeInstanceOf(SendQueuedNotifications::class);

    return $job;
}

/** What the worker does with one job: its middleware around handle(), then a fresh scope. */
function mqRun(SendQueuedNotifications $job): void
{
    try {
        (new Pipeline(app()))->send($job)->through($job->middleware)
            ->then(fn (SendQueuedNotifications $job) => $job->handle(app(ChannelManager::class)));
    } finally {
        app()->forgetScopedInstances();
    }
}

function mqToday(int $site = 1): string
{
    return app(Tenancy::class)->enter($site, fn (): string => app(SiteTime::class)->now()->toDateString());
}

function mqDay(string $via = 'platform', int $site = 1): object
{
    $row = DB::connection('pgsql_admin')->table('mail_daily')->where('site_id', $site)->where('via', $via)->first();

    return $row ?? (object) ['sent' => 0, 'failed' => 0, 'held' => 0];
}

function mqSetSent(int $sent, int $site = 1): void
{
    DB::connection('pgsql_admin')->table('mail_daily')->insert(['site_id' => $site, 'day' => mqToday($site), 'via' => 'platform', 'sent' => $sent]);
}

function mqLimit(int $value, int $site = 1): void
{
    app(Tenancy::class)->enter($site, fn () => SiteFeature::query()->create(['feature_key' => MailQuotaGate::FEATURE, 'value' => $value]));
}

/** @return Collection<int, object> */
function mqLogs(): Collection
{
    return DB::connection('pgsql_admin')->table('mail_logs')->orderBy('id')->get();
}

it('adds the daily limit to the mail job only, after the website context', function (): void {
    $note = mqInvite();

    $mail = $note->middleware(null, 'mail');
    expect($mail)->toHaveCount(2)
        ->and($mail[0])->toBeInstanceOf(WithinSite::class)
        ->and($mail[1])->toBeInstanceOf(MailQuota::class)
        ->and($note->middleware(null, 'site_database'))->toHaveCount(1)
        ->and($note->middleware())->toHaveCount(1);

    $platform = mqInvite();
    $platform->tenantSiteId = null;
    expect($platform->middleware(null, 'mail'))->toBe([]);
});

it('counts an ordinary mail through the shared outbox once, when it takes its place', function (): void {
    $job = mqQueue(mqInvite(), 'moi@example.test');
    mqRun($job);

    expect($this->mqTransport->messages())->toHaveCount(1)
        ->and(mqDay()->sent)->toBe(1)
        ->and(mqDay()->held)->toBe(0);

    $logs = mqLogs();
    expect($logs)->toHaveCount(1)
        ->and($logs[0]->kind)->toBe('invitation')
        ->and($logs[0]->via)->toBe('platform')
        ->and($logs[0]->status)->toBe('sent')
        ->and($logs[0]->to_first)->toBe('moi@example.test')
        ->and($logs[0]->notification_id)->toBe($job->notification->id)
        ->and((int) $logs[0]->site_id)->toBe(1);
});

it('counts a security mail once after it left', function (): void {
    mqRun(mqQueue(mqOtp()));
    mqRun(mqQueue(mqOtp()));

    expect($this->mqTransport->messages())->toHaveCount(2)
        ->and(mqDay()->sent)->toBe(2)
        ->and(mqLogs()->pluck('kind')->all())->toBe(['email_otp', 'email_otp']);
});

it('holds an ordinary mail past ten times the limit and queues the same mail for the next day', function (): void {
    mqLimit(1); // ceiling 10
    mqSetSent(10);
    $job = mqQueue(mqInvite());

    mqRun($job);

    expect($this->mqTransport->messages())->toHaveCount(0)
        ->and(mqDay()->sent)->toBe(10)
        ->and(mqDay()->held)->toBe(1)
        ->and(mqLogs())->toHaveCount(0);

    $pushed = Queue::pushed(SendQueuedNotifications::class);
    expect($pushed)->toHaveCount(2);
    $copy = $pushed->last();
    expect($copy)->not->toBe($job)
        ->and($copy->notification->id)->toBe($job->notification->id)
        ->and($copy->channels)->toBe(['mail'])
        ->and($copy->connection)->toBe($job->connection)
        ->and($copy->queue)->toBe($job->queue)
        ->and($copy->middleware)->toHaveCount(2)
        ->and($copy->middleware[0])->toBeInstanceOf(WithinSite::class)
        ->and($copy->middleware[1])->toBeInstanceOf(MailQuota::class)
        ->and($copy->delay)->toBeInstanceOf(DateTimeInterface::class)
        ->and($copy->delay->getTimestamp())->toBeGreaterThan(now()->getTimestamp());

    // Not before the website's next midnight, and within the 15 minutes the held mails spread over.
    $midnight = app(Tenancy::class)->enter(1, fn () => app(SiteTime::class)->now()->addDay()->startOfDay());
    expect($copy->delay->getTimestamp())->toBeGreaterThanOrEqual($midnight->getTimestamp())
        ->and($copy->delay->getTimestamp())->toBeLessThanOrEqual($midnight->getTimestamp() + 900);

    Event::assertDispatchedTimes(MailQuotaReached::class, 1);
    Event::assertDispatched(MailQuotaReached::class, fn (MailQuotaReached $e): bool => $e->threshold === 'site_abuse' && $e->siteId === 1 && $e->limit === 1);

    // A second held mail the same day: held again, the operator is not told twice.
    mqRun(mqQueue(mqInvite()));
    expect(mqDay()->held)->toBe(2);
    Event::assertDispatchedTimes(MailQuotaReached::class, 1);
});

it('runs the held copy later through the same limit', function (): void {
    mqLimit(1);
    mqSetSent(10);
    mqRun(mqQueue(mqInvite()));
    $copy = Queue::pushed(SendQueuedNotifications::class)->last();

    // Next day's count: a fresh row lets the copy through.
    DB::connection('pgsql_admin')->table('mail_daily')->where('site_id', 1)->update(['sent' => 3]);
    mqRun($copy);

    expect($this->mqTransport->messages())->toHaveCount(1)
        ->and(mqDay()->sent)->toBe(4)
        ->and(mqLogs()->pluck('notification_id')->all())->toBe([$copy->notification->id]);
});

it('holds an ordinary mail at once when the limit is 0', function (): void {
    mqLimit(0);

    mqRun(mqQueue(mqInvite()));

    expect($this->mqTransport->messages())->toHaveCount(0)
        ->and(mqDay()->held)->toBe(1)
        ->and(mqDay()->sent)->toBe(0)
        ->and(Queue::pushed(SendQueuedNotifications::class))->toHaveCount(2);
});

it('never holds a security mail past the ceiling', function (): void {
    mqLimit(0);
    mqRun(mqQueue(mqOtp()));

    mqLimit(1, 2);
    mqSetSent(10, 2);
    mqRun(mqQueue(mqOtp(2)));

    expect($this->mqTransport->messages())->toHaveCount(2)
        ->and(mqDay('platform', 1)->sent)->toBe(1)
        ->and(mqDay('platform', 2)->sent)->toBe(11)
        ->and(mqDay('platform', 2)->held)->toBe(0)
        ->and(Queue::pushed(SendQueuedNotifications::class))->toHaveCount(2);
});

it('holds only the ordinary kinds', function (string $kind, bool $holdable): void {
    expect(MailKinds::holdable($kind))->toBe($holdable);
})->with([
    ['invitation', true], ['domain_notice', true], ['domain_help', true],
    ['lead', false], ['password_reset', false], ['email_otp', false], ['email_change_code', false],
    ['email_changed', false], ['support_sign_in', false], ['owner_recovered', false],
    ['two_factor_disabled', false], ['email_verification', false], ['other', false],
]);

it('never limits mail through the website own outbox', function (): void {
    mqOwnOutbox();
    mqLimit(0);

    mqRun(mqQueue(mqInvite()));

    expect($this->mqTransport->messages())->toHaveCount(1)
        ->and(mqDay('own')->sent)->toBe(1)
        ->and(mqDay('platform')->sent)->toBe(0)
        ->and(mqDay('platform')->held)->toBe(0)
        ->and(mqLogs()->pluck('via')->all())->toBe(['own']);
});

it('holds ordinary mail while the platform ceiling is reached, and still counts every platform mail', function (): void {
    config(['mail.quota.platform_daily_cap' => 5]);
    Cache::put('mail-platform-day:'.now('UTC')->toDateString(), 5, now()->addDay());

    mqRun(mqQueue(mqInvite()));
    $copy = Queue::pushed(SendQueuedNotifications::class)->last();

    expect($this->mqTransport->messages())->toHaveCount(0)
        ->and(mqDay()->held)->toBe(1)
        ->and($copy->delay->getTimestamp())->toBeGreaterThanOrEqual(now('UTC')->addDay()->startOfDay()->getTimestamp());

    mqRun(mqQueue(mqOtp()));
    expect($this->mqTransport->messages())->toHaveCount(1)
        ->and(Cache::get('mail-platform-day:'.now('UTC')->toDateString()))->toBe(6);
});

it('reads the platform ceiling from the platform settings before the config', function (): void {
    config(['mail.quota.platform_daily_cap' => 5]);
    expect(app(MailQuotaGate::class)->platformCap())->toBe(5);

    app(PlatformSettings::class)->put('email', 'daily_cap', ['cap' => 500]);
    expect(app(MailQuotaGate::class)->platformCap())->toBe(500);

    config(['mail.quota.platform_daily_cap' => null]);
    app(PlatformSettings::class)->put('email', 'daily_cap', []);
    expect(app(MailQuotaGate::class)->platformCap())->toBeNull();
});

it('tells the operator once when the platform reaches 90% of its ceiling', function (): void {
    config(['mail.quota.platform_daily_cap' => 10]);
    Cache::put('mail-platform-day:'.now('UTC')->toDateString(), 8, now()->addDay());

    mqRun(mqQueue(mqOtp()));
    mqRun(mqQueue(mqOtp()));

    Event::assertDispatchedTimes(MailQuotaReached::class, 1);
    Event::assertDispatched(MailQuotaReached::class, fn (MailQuotaReached $e): bool => $e->threshold === 'platform_90' && $e->siteId === null && $e->count === 9 && $e->limit === 10);
});

it('gives the place back when the send fails, and logs the failure only once it failed for good', function (): void {
    Mail::extend('smtp', fn (): AbstractTransport => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection could not be established with host "smtp.nen-tang.test:587"');
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    });
    $job = mqQueue(mqInvite());

    $error = null;
    try {
        mqRun($job);
    } catch (TransportException $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull()
        ->and(mqDay()->sent)->toBe(0)
        ->and(mqLogs())->toHaveCount(0)
        ->and(Queue::pushed(SendQueuedNotifications::class))->toHaveCount(1);

    // The worker's last try: the job's failed() → the notification's failed().
    $job->failed($error);

    $logs = mqLogs();
    expect($logs)->toHaveCount(1)
        ->and($logs[0]->status)->toBe('failed')
        ->and($logs[0]->reason_code)->toBe('connection')
        ->and($logs[0]->kind)->toBe('invitation')
        ->and($logs[0]->via)->toBe('platform')
        ->and($logs[0]->notification_id)->toBe($job->notification->id)
        ->and(mqDay()->failed)->toBe(1)
        ->and(mqDay()->sent)->toBe(0);
});

it('tells the website once at 80% and once at 100% of its limit', function (): void {
    mqLimit(5);

    foreach (range(1, 3) as $i) {
        mqRun(mqQueue(mqInvite()));
    }
    Event::assertNotDispatched(MailQuotaReached::class);

    mqRun(mqQueue(mqInvite())); // 4 of 5
    mqRun(mqQueue(mqOtp()));    // 5 of 5, counted after it left
    mqRun(mqQueue(mqInvite())); // 6, past the limit but under the ceiling: sent

    expect($this->mqTransport->messages())->toHaveCount(6)
        ->and(mqDay()->sent)->toBe(6);
    Event::assertDispatchedTimes(MailQuotaReached::class, 2);
    Event::assertDispatched(MailQuotaReached::class, fn (MailQuotaReached $e): bool => $e->threshold === 'site_80' && $e->count === 4);
    Event::assertDispatched(MailQuotaReached::class, fn (MailQuotaReached $e): bool => $e->threshold === 'site_100' && $e->count === 5);
    expect(Cache::has(MailQuotaGate::noticeKey('site_80', 1, mqToday())))->toBeTrue()
        ->and(MailQuotaGate::noticeKey('site_abuse', 1, '2026-11-26'))->toBe('mail-quota-notified:1:2026-11-26:abuse');
});

it('does not hold a mail sent outside a queued job', function (): void {
    mqLimit(0);

    app(Tenancy::class)->enter(1, function (): void {
        Notifications::route('mail', 'ngay@example.test')->notifyNow(mqInvite());
    });

    expect($this->mqTransport->messages())->toHaveCount(1)
        ->and(mqDay()->sent)->toBe(1)
        ->and(mqDay()->held)->toBe(0);
});
