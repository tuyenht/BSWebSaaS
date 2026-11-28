<?php

use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fixtures\FakeDns;

/*
 * «Gửi thư kiểm tra tới»: a test mail goes to the one address typed in (empty = the member's own).
 * Through the website's own outbox any address takes one, at most 20 a day per website (its own
 * day); through the platform's shared outbox only a verified email of a member of this website.
 * Mail is stopped at MessageSending, so nothing leaves and the recipients are read from there.
 */

beforeEach(function (): void {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    app()->instance(MailHostGuard::class, new MailHostGuard(fn (string $host): array => ['93.184.216.34']));
    Cache::forget('mail-own-outbox-failing:1');
    Cache::forget('mail-own-outbox-failing:2');

    $sent = new ArrayObject;
    app()->instance('ets.sent', $sent);
    Event::listen(MessageSending::class, function (MessageSending $event) use ($sent): bool {
        $sent->append(array_map(fn ($a): string => $a->getAddress(), $event->message->getTo()));

        return false; // never leaves
    });
    FakeDns::install(); // «Kiểm tra & lưu kết nối» also checks the domain's records: no lookup leaves the test
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
});

/** A unique address on congty.vn (users outlive a test): «quan.tri» → «quan.tri.x7k2qa@congty.vn». */
function etsEmail(string $name): string
{
    return $name.'.'.Str::lower(Str::random(6)).'@congty.vn';
}

function etsAdmin(int $site = 1, string $email = ''): User
{
    $admin = User::factory()->create($email === '' ? [] : ['email' => $email]);
    app(Tenancy::class)->enter($site, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

function etsMember(int $site, string $email, bool $verified): User
{
    $member = User::factory()->create(['email' => $email, 'email_verified_at' => $verified ? now() : null]);
    app(Tenancy::class)->enter($site, fn () => grantRole($member, 'editor', ['entries.view']));

    return $member;
}

/** The website's own outbox: a mail service that sends (its host passes the stubbed guard). */
function etsOwnOutbox(int $site = 1): void
{
    app(Tenancy::class)->enter($site, fn () => app(SettingsRepository::class)->put('email', 'config', [
        'provider' => 'other', 'method' => 'smtp_password', 'relay' => 'sendgrid', 'send_domain' => 'congty.vn',
        'smtp' => ['host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey', 'password' => SecretCipher::encrypt('SG.k')],
    ]));
}

/** Only the platform's shared outbox is set up: the website sends through it. */
function etsPlatformOutbox(): void
{
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'no-reply@nen-tang.test',
        'smtp' => ['host' => 'smtp.nen-tang.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'relay', 'password' => SecretCipher::encrypt('pw')],
    ]);
}

/** Presses «Gửi thư kiểm tra» with $to typed; a minute apart, so the per-minute limit never steps in. */
function etsSend(User $admin, string $to, int $site = 1): mixed
{
    Carbon::setTestNow(Carbon::now()->addSeconds(61));

    return app(Tenancy::class)->enter($site, fn () => Livewire::actingAs($admin)->test(EmailSettings::class)
        ->set('testTo', $to)
        ->call('sendTest'));
}

/** @return list<list<string>> */
function etsSent(): array
{
    return app('ets.sent')->getArrayCopy();
}

it('sends the test mail through the website\'s own outbox to the address typed in', function (): void {
    etsOwnOutbox();
    $admin = etsAdmin();

    $page = etsSend($admin, '  khach@ngoai.vn ');

    $page->assertHasNoErrors()->assertSet('testResult', 'ok');
    expect(etsSent())->toBe([['khach@ngoai.vn']])
        ->and($page->get('testSentNote'))->toStartWith('Đã gửi tới khach@ngoai.vn lúc ');
});

it('fills the field with the member\'s own address, and mails the member when it is emptied', function (): void {
    etsOwnOutbox();
    $admin = etsAdmin(email: etsEmail('quan.tri'));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)->assertSet('testTo', $admin->email);
    });
    etsSend($admin, '   ')->assertHasNoErrors()->assertSet('testResult', 'ok');

    expect(etsSent())->toBe([[$admin->email]]);
});

it('refuses the 21st test mail of a website\'s day on its own outbox, for every member, and leaves other websites alone', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-28 03:00:00', 'UTC')); // 10:00 in the websites' time zone (GMT+7)
    etsOwnOutbox(1);
    etsOwnOutbox(2);
    $admin = etsAdmin(1);
    $other = etsAdmin(1);
    $elsewhere = etsAdmin(2);

    foreach (range(1, 20) as $i) {
        etsSend($i % 2 === 0 ? $admin : $other, "khach{$i}@ngoai.vn")->assertHasNoErrors()->assertSet('testResult', 'ok');
    }
    $refused = etsSend($admin, 'khach21@ngoai.vn');
    $refusedOther = etsSend($other, 'khach22@ngoai.vn');

    $full = __('admin.em_test_day_full', ['count' => EmailSettings::TEST_SENDS_PER_DAY]);
    expect($refused->errors()->first('testTo'))->toBe($full)
        ->and($refused->get('testResult'))->toBeNull()
        ->and($refusedOther->errors()->first('testTo'))->toBe($full)
        ->and(etsSent())->toHaveCount(20);

    etsSend($elsewhere, 'khach@website-b.vn', 2)->assertHasNoErrors()->assertSet('testResult', 'ok');
    expect(etsSent())->toHaveCount(21)->and(etsSent()[20])->toBe(['khach@website-b.vn']);
});

it('starts a new count on the website\'s next day (its own time zone, not UTC)', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-28 16:00:00', 'UTC')); // 23:00 in GMT+7
    etsOwnOutbox();
    $admin = etsAdmin();

    foreach (range(1, 20) as $i) {
        etsSend($admin, "khach{$i}@ngoai.vn")->assertSet('testResult', 'ok');
    }
    etsSend($admin, 'khach21@ngoai.vn')->assertHasErrors('testTo');

    // 00:01 the next day in GMT+7 — still 28/11 in UTC
    Carbon::setTestNow(Carbon::parse('2026-11-28 17:01:00', 'UTC'));
    etsSend($admin, 'khach-moi@ngoai.vn')->assertHasNoErrors()->assertSet('testResult', 'ok');

    expect(etsSent())->toHaveCount(21)->and(etsSent()[20])->toBe(['khach-moi@ngoai.vn']);
});

it('through the shared outbox, refuses an address that is no verified email of a member of this website', function (string $who): void {
    etsPlatformOutbox();
    $admin = etsAdmin();
    $to = match ($who) {
        'stranger' => 'la@ngoai.vn',
        'unverified member' => etsMember(1, etsEmail('chua.xac.minh'), verified: false)->email,
        'member of another website' => etsMember(2, etsEmail('nguoi.b'), verified: true)->email,
    };

    $page = etsSend($admin, $to);

    expect($page->errors()->first('testTo'))->toBe(__('admin.em_test_platform_only'))
        ->and($page->get('testResult'))->toBeNull()
        ->and(etsSent())->toBe([]);
})->with(['stranger', 'unverified member', 'member of another website']);

it('through the shared outbox, sends to a verified member of this website and to the member who presses it', function (): void {
    etsPlatformOutbox();
    $admin = etsAdmin(email: etsEmail('quan.tri'));
    $editor = etsMember(1, etsEmail('bien.tap'), verified: true);
    $typed = Str::upper($editor->email); // the address matches whatever its case

    etsSend($admin, $typed)->assertHasNoErrors()->assertSet('testResult', 'ok');
    etsSend($admin, '')->assertHasNoErrors()->assertSet('testResult', 'ok');

    expect(etsSent())->toBe([[$typed], [$admin->email]]);
});

it('refuses a malformed address and more than one address, sending nothing', function (string $typed, string $key): void {
    etsOwnOutbox();
    $admin = etsAdmin();

    $page = etsSend($admin, $typed);

    expect($page->errors()->first('testTo'))->toBe(__($key))
        ->and($page->get('testResult'))->toBeNull()
        ->and(etsSent())->toBe([]);
})->with([
    'no domain' => ['khach@', 'admin.em_test_bad'],
    'not an address' => ['khong-phai-email', 'admin.em_test_bad'],
    'two, comma' => ['a@ngoai.vn, b@ngoai.vn', 'admin.em_test_one'],
    'two, semicolon' => ['a@ngoai.vn;b@ngoai.vn', 'admin.em_test_one'],
    'a header after a line break' => ["a@ngoai.vn\r\nBcc: b@ngoai.vn", 'admin.em_test_one'],
]);

it('refuses the test send to a member without settings.manage', function (): void {
    etsOwnOutbox();
    $admin = etsAdmin();
    $member = etsMember(1, etsEmail('bien.tap'), verified: true);

    app(Tenancy::class)->enter(1, function () use ($admin, $member): void {
        Livewire::actingAs($member)->test(EmailSettings::class)->assertForbidden();
        // a page opened by someone who may, then used by a member who may not
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)->set('testTo', 'khach@ngoai.vn');
        $this->actingAs($member);
        $page->call('sendTest')->assertForbidden();
    });

    expect(etsSent())->toBe([]);
});

it('never takes a test mail of the website\'s day through the shared outbox', function (): void {
    etsPlatformOutbox();
    $admin = etsAdmin();

    foreach (range(1, EmailSettings::TEST_SENDS_PER_DAY + 1) as $i) {
        etsSend($admin, '')->assertHasNoErrors()->assertSet('testResult', 'ok');
    }

    expect(etsSent())->toHaveCount(EmailSettings::TEST_SENDS_PER_DAY + 1);
});

it('mails the member on «Kiểm tra & lưu kết nối» whatever is typed, and keeps an earlier check once the day is used up', function (): void {
    $admin = etsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')->set('smtpPassword', 'SG.moi')->set('sendDomain', 'congty.vn')
            ->set('testTo', 'la@ngoai.vn')
            ->call('checkRelay')
            ->assertHasNoErrors()->assertSet('relayChecked', true);
    });
    expect(etsSent())->toBe([[$admin->email]]);
    $checkedAt = app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->get('email')['checked_at'] ?? null);
    expect($checkedAt)->not->toBeNull();

    foreach (range(2, EmailSettings::TEST_SENDS_PER_DAY) as $i) {
        etsSend($admin, "khach{$i}@ngoai.vn")->assertSet('testResult', 'ok');
    }
    Carbon::setTestNow(Carbon::now()->addSeconds(61));
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)->call('checkRelay');
        expect($page->errors()->first('relay'))->toBe(__('admin.em_test_day_full', ['count' => EmailSettings::TEST_SENDS_PER_DAY]));
    });

    expect(etsSent())->toHaveCount(EmailSettings::TEST_SENDS_PER_DAY)
        ->and(app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->get('email')['checked_at'] ?? null))->toBe($checkedAt);
});

it('mails only the operator from the system\'s own page, whatever the browser sends as the address', function (): void {
    etsPlatformOutbox();
    $operator = User::factory()->create(['email' => etsEmail('van.hanh')]);
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    app(PlatformAccess::class)->onPlatform(function () use ($operator): void {
        Livewire::actingAs($operator->fresh())->test(EmailSettings::class, ['scope' => 'platform'])
            ->assertSet('testTo', '')
            ->set('testTo', 'la@ngoai.vn')
            ->call('sendTest')
            ->assertSet('testResult', 'ok');
    });

    expect(etsSent())->toBe([[$operator->email]]);
});

it('ties the field to its label and to the line under it: the hint, the error, then what was sent', function (): void {
    etsOwnOutbox();
    $admin = etsAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)
            ->assertSeeHtml('<label for="em-test-to"')
            ->assertSeeHtml('aria-describedby="em-test-idle"')
            ->assertDontSeeHtml('aria-invalid="true" x-bind:aria-invalid')
            ->assertSee(__('admin.em_test_send'));

        $page->set('testTo', 'khong-phai-email')->call('sendTest')
            ->assertSeeHtml('aria-describedby="em-test-msg"')
            ->assertSeeHtml('aria-invalid="true" x-bind:aria-invalid')
            ->assertSeeHtml('id="em-test-msg"')
            ->assertSee(__('admin.em_test_bad'));

        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $page->set('testTo', 'khach@ngoai.vn')->call('sendTest')
            ->assertSeeHtml('aria-describedby="em-test-msg"')
            ->assertDontSeeHtml('aria-invalid="true" x-bind:aria-invalid')
            ->assertSee('Đã gửi tới khach@ngoai.vn lúc')
            ->assertSee(__('admin.em_test_again'));
    });
});
