<?php

use App\Modules\Admin\Models\MailLog;
use App\Modules\Admin\Notifications\EmailChangeCodeNotification;
use App\Modules\Admin\Notifications\EmailOtpNotification;
use App\Modules\Admin\Notifications\ResetPasswordLinkNotification;
use App\Modules\Admin\Notifications\TwoFactorDisabledNotification;
use App\Modules\Admin\Support\Mail\MailKinds;
use App\Modules\Admin\Support\Mail\MailReason;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Forms\Actions\SubmitForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Notifications\NewLeadNotification;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as Notifications;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
 * The website's mail log («Thư gần đây»): one row per website notification mail, sent or failed,
 * with its kind, the outbox it went through, how many it was for, the first address and, for a
 * failure, why in one plain code. A mail the system could not set up a server for went to the log
 * mailer and never reached anyone: it is logged as failed with that reason. Test sends and mail on
 * another mailer (the operator notices) are not the website's mail and are not logged. Rows are the
 * website's alone (RLS on site_id).
 */

beforeEach(function (): void {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef'), 'services.turnstile.secret_key' => '']);
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings, mail_logs restart identity cascade');
    $this->mlogTransport = new ArrayTransport;
    $transport = $this->mlogTransport;
    Mail::extend('smtp', fn (): ArrayTransport => $transport);
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings, mail_logs restart identity cascade');
});

function mlogPlatformOutbox(): void
{
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'no-reply@nen-tang.test',
        'smtp' => ['host' => 'smtp.nen-tang.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'relay', 'password' => SecretCipher::encrypt('pw')],
    ]);
}

/** The website's own server, resolving to $ip. */
function mlogOwnOutbox(string $ip): void
{
    app()->instance(MailHostGuard::class, new MailHostGuard(fn (string $host): array => [$ip]));
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', [
        'provider' => 'other', 'method' => 'smtp_password', 'from_address' => 'info@anvinh.test',
        'smtp' => ['host' => 'smtp.anvinh.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'password' => SecretCipher::encrypt('own')],
    ]));
}

/** Sends a security code of site $site now, in its context, as a request does. */
function mlogSendOtp(int $site = 1, string $to = 'khach@example.test'): void
{
    app(Tenancy::class)->enter($site, function () use ($site, $to): void {
        $note = new EmailOtpNotification('123456', 10);
        $note->tenantSiteId = $site;
        Notifications::route('mail', $to)->notifyNow($note);
    });
}

/** A security notice: ordinary account mail, sent through the website's own outbox when it has one. */
function mlogSendNotice(int $site = 1, string $to = 'khach@example.test'): void
{
    app(Tenancy::class)->enter($site, function () use ($site, $to): void {
        $note = new TwoFactorDisabledNotification('28/11/2026 10:00');
        $note->tenantSiteId = $site;
        Notifications::route('mail', $to)->notifyNow($note);
    });
}

/** @return Collection<int, object> */
function mlogRows(): Collection
{
    return DB::connection('pgsql_admin')->table('mail_logs')->orderBy('id')->get();
}

function mlogDay(string $via, int $site = 1): ?object
{
    return DB::connection('pgsql_admin')->table('mail_daily')->where('site_id', $site)->where('via', $via)->first();
}

function mlogFailingTransport(): void
{
    Mail::extend('smtp', fn (): AbstractTransport => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".', 535);
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    });
}

function mlogForm(): Form
{
    return app(Tenancy::class)->enter(1, fn () => Form::query()->create([
        'key' => 'lien-he', 'name' => ['vi' => 'Liên hệ'], 'notify_emails' => ['sales@anvinh.test', 'boss@anvinh.test'],
    ]));
}

function mlogSubmit(Form $form): FormSubmission
{
    return app(Tenancy::class)->enter(1, fn () => app(SubmitForm::class)->handle(
        $form,
        ['name' => 'Khách A', 'email' => 'a@example.test', 'message' => 'Báo giá'],
        honeypot: null,
        turnstileToken: null,
        meta: ['ip' => '203.0.113.7', 'user_agent' => 'PestUA', 'source_url' => 'https://a.test/lien-he'],
    ));
}

it('logs a mail that left through the shared outbox', function (): void {
    mlogPlatformOutbox();

    mlogSendOtp(1, 'Khach@Example.test');

    $rows = mlogRows();
    expect($this->mlogTransport->messages())->toHaveCount(1)
        ->and($rows)->toHaveCount(1)
        ->and((int) $rows[0]->site_id)->toBe(1)
        ->and($rows[0]->kind)->toBe('email_otp')
        ->and($rows[0]->via)->toBe('platform')
        ->and($rows[0]->status)->toBe('sent')
        ->and($rows[0]->reason_code)->toBeNull()
        ->and((int) $rows[0]->recipient_count)->toBe(1)
        ->and($rows[0]->to_first)->toBe('Khach@Example.test')
        ->and($rows[0]->notification_id)->not->toBeNull()
        ->and(mlogDay('platform')->sent)->toBe(1);
});

it('logs a mail that went to the log mailer as failed, with why', function (): void {
    // Nothing set up anywhere: the safe log mailer, and the mail reached nobody.
    mlogSendOtp();

    $rows = mlogRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->status)->toBe('failed')
        ->and($rows[0]->reason_code)->toBe('config')
        ->and($rows[0]->via)->toBe('platform')
        ->and(mlogDay('platform')->failed)->toBe(1)
        ->and(mlogDay('platform')->sent)->toBe(0);
});

it('logs a refused own server as failed with that reason, and as sent when the shared outbox took over', function (): void {
    mlogOwnOutbox('10.0.0.5'); // a private address: never dialled

    mlogSendNotice();
    expect(mlogRows()->last()->status)->toBe('failed')
        ->and(mlogRows()->last()->reason_code)->toBe('refused_host')
        ->and(mlogRows()->last()->via)->toBe('own')
        ->and(mlogDay('own')->failed)->toBe(1);

    mlogPlatformOutbox();
    mlogSendNotice();
    expect(mlogRows()->last()->status)->toBe('sent')
        ->and(mlogRows()->last()->via)->toBe('platform')
        ->and(mlogDay('platform')->sent)->toBe(1)
        ->and($this->mlogTransport->messages())->toHaveCount(1);
});

it('sends the email verification mail through the platform outbox, even when the website has its own, and logs it', function (): void {
    mlogPlatformOutbox();
    mlogOwnOutbox('93.184.216.34');
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, fn () => $user->notifyNow(new VerifyEmail));

    $rows = mlogRows();
    expect($this->mlogTransport->messages())->toHaveCount(1)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->kind)->toBe('email_verification')
        ->and($rows[0]->via)->toBe('platform');
});

it('logs a queued account mail that failed for good under the platform outbox, an ordinary one under the website\'s own', function (): void {
    mlogPlatformOutbox();
    mlogOwnOutbox('93.184.216.34'); // a working own server: the website's ordinary mail would use it

    foreach ([
        new EmailOtpNotification('123456', 10),
        new EmailChangeCodeNotification('123456', 10, true),
        new ResetPasswordLinkNotification('https://a.test/admin/reset-password?token=t'),
        new TwoFactorDisabledNotification('28/11/2026 10:00'),
    ] as $note) {
        $note->tenantSiteId = 1;
        $note->failed(new TransportException('down'));
    }

    expect(mlogRows()->pluck('via')->all())->toBe(['platform', 'platform', 'platform', 'own'])
        ->and(mlogRows()->pluck('status')->unique()->all())->toBe(['failed'])
        ->and(mlogDay('platform')->failed)->toBe(3)
        ->and(mlogDay('own')->failed)->toBe(1);
});

it('does not wait long for a day row another job holds: the mail leaves and its record is kept', function (): void {
    mlogPlatformOutbox();
    $day = app(Tenancy::class)->enter(1, fn (): string => app(SiteTime::class)->now()->toDateString());
    $admin = DB::connection('pgsql_admin');
    $admin->table('mail_daily')->insert(['site_id' => 1, 'day' => $day, 'via' => 'platform', 'sent' => 5]);
    $admin->beginTransaction();
    $admin->select("select sent from mail_daily where site_id = 1 and day = ?::date and via = 'platform' for update", [$day]);

    try {
        $started = microtime(true);
        mlogSendOtp();
        $took = microtime(true) - $started;
    } finally {
        $admin->rollBack();
    }

    expect($this->mlogTransport->messages())->toHaveCount(1)
        ->and(mlogRows())->toHaveCount(1)
        ->and($took)->toBeLessThan(10.0)
        ->and(mlogDay('platform')->sent)->toBe(5);
});

it('logs a mail through the website own outbox under own', function (): void {
    mlogPlatformOutbox();
    mlogOwnOutbox('93.184.216.34');

    mlogSendNotice();

    expect(mlogRows()->pluck('via')->all())->toBe(['own'])
        ->and(mlogDay('own')->sent)->toBe(1)
        ->and(mlogDay('platform'))->toBeNull();
});

it('does not log a test send, a mail on another mailer or a mail with no website', function (): void {
    mlogPlatformOutbox();

    app(Tenancy::class)->enter(1, function (): void {
        app(TenantMailer::class)->apply(fallback: false);
        Mail::raw('thử', fn ($m) => $m->to('thu@example.test')->subject('Thư thử'));
        Mail::mailer('array')->raw('khác', fn ($m) => $m->to('khac@example.test'));
    });

    // The platform plane: no website, nothing of a website's to log.
    app(TenantMailer::class)->apply();
    Notifications::route('mail', 'ops@example.test')->notifyNow(new EmailOtpNotification('123456', 10));

    expect($this->mlogTransport->messages())->toHaveCount(2)
        ->and(mlogRows())->toHaveCount(0)
        ->and(DB::connection('pgsql_admin')->table('mail_daily')->count())->toBe(0);
});

it('logs the contact-form notice, and its failure without losing the lead', function (): void {
    mlogPlatformOutbox();
    $form = mlogForm();

    mlogSubmit($form);
    expect(mlogRows()->last()->kind)->toBe('lead')
        ->and(mlogRows()->last()->status)->toBe('sent')
        ->and((int) mlogRows()->last()->recipient_count)->toBe(2)
        ->and(mlogRows()->last()->to_first)->toBe('sales@anvinh.test');

    mlogFailingTransport();
    $submission = mlogSubmit($form);

    $last = mlogRows()->last();
    expect($submission->exists)->toBeTrue()
        ->and(mlogRows())->toHaveCount(2)
        ->and($last->kind)->toBe('lead')
        ->and($last->status)->toBe('failed')
        ->and($last->reason_code)->toBe('auth')
        ->and($last->via)->toBe('platform')
        ->and($last->to_first)->toBe('sales@anvinh.test')
        ->and(mlogDay('platform')->sent)->toBe(1)
        ->and(mlogDay('platform')->failed)->toBe(1);
});

it('tells why a mail failed in one plain code', function (Throwable $e, string $reason): void {
    expect(MailReason::of($e))->toBe($reason);
})->with([
    'sign-in refused' => [new TransportException('Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".', 535), 'auth'],
    'address refused' => [new TransportException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 User unknown".', 550), 'rejected'],
    'server unreachable' => [new TransportException('Connection could not be established with host "smtp.example.test:587": stream_socket_client(): Unable to connect'), 'connection'],
    'timed out' => [new TransportException('Connection to "smtp.example.test:587" timed out.'), 'connection'],
    'secret unreadable' => [new DecryptException('The payload is invalid.'), 'config'],
    'wrapped' => [new RuntimeException('send failed', 0, new TransportException('Failed to authenticate on SMTP server with username "x"', 0)), 'auth'],
    'anything else' => [new RuntimeException('boom'), 'other'],
]);

it('keeps the rows of each website to that website', function (): void {
    foreach ([1, 2] as $site) {
        app(Tenancy::class)->enter($site, fn () => MailLog::query()->create([
            'site_id' => $site, 'kind' => 'lead', 'via' => 'platform', 'recipient_count' => 1, 'status' => 'sent',
        ]));
    }

    app(Tenancy::class)->enter(1, function (): void {
        expect(MailLog::query()->count())->toBe(1)
            ->and((int) DB::scalar('select count(*) from mail_logs'))->toBe(1)
            ->and((int) DB::scalar('select count(*) from mail_daily where site_id = 2'))->toBe(0);
    });
    expect((int) DB::scalar('select count(*) from mail_logs'))->toBe(0); // no website: nothing

    expect(fn () => app(Tenancy::class)->enter(1, fn () => DB::table('mail_logs')->insert([
        'site_id' => 2, 'kind' => 'lead', 'via' => 'platform', 'recipient_count' => 1, 'status' => 'sent',
    ])))->toThrow(QueryException::class);
});

it('knows the kind of every website mail, and the database accepts every kind and reason', function (): void {
    $classes = [VerifyEmail::class, NewLeadNotification::class];
    foreach (File::allFiles(app_path('Modules')) as $file) {
        $source = $file->getContents();
        if (! str_contains($source, 'use QueuesTenantMail;')) {
            continue;
        }
        preg_match('/^namespace (.+);$/m', $source, $ns);
        $class = $ns[1].'\\'.$file->getFilenameWithoutExtension();
        $classes[] = $class;

        // Every queued website mail logs its failure from failed().
        expect($source)->toContain('public function failed(Throwable $e): void')
            ->and($source)->toContain('$this->recordMailFailure($e);');
    }

    expect(count($classes))->toBeGreaterThan(10);
    foreach ($classes as $class) {
        expect(array_key_exists($class, MailKinds::KINDS))->toBeTrue("{$class} has no mail kind");
    }

    $checks = implode(' ', array_map(
        fn (object $row): string => (string) $row->def,
        DB::connection('pgsql_admin')->select("select pg_get_constraintdef(oid) as def from pg_constraint where conrelid = 'public.mail_logs'::regclass and contype = 'c'"),
    ));
    foreach ([...MailKinds::all(), ...MailReason::CODES] as $code) {
        expect($checks)->toContain("'{$code}'");
    }
});
