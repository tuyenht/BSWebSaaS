<?php

use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\MailRelay;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * «Nhà cung cấp khác» → «Chọn dịch vụ»: a website picks one of seven mail services or «Email khác»,
 * and mail leaves through that service's own SMTP relay. The server, port and encryption of a
 * service come from the relay table on the server, never from the browser; the key is stored
 * encrypted, kept when the field is left blank, and never carried over to another service.
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
});

function erlAdmin(): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

/** @param array<string, mixed> $cfg */
function erlStore(array $cfg): void
{
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', $cfg));
}

/** @return array<string, mixed> */
function erlStored(): array
{
    return app(SettingsRepository::class)->get('email') ?? [];
}

function erlSendgrid(string $key = 'SG.cu'): void
{
    erlStore(['provider' => 'other', 'method' => 'smtp_password', 'relay' => 'sendgrid', 'send_domain' => 'congty.vn',
        'smtp' => ['host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey', 'password' => SecretCipher::encrypt($key)]]);
}

it('sends each service through its own relay, with the login that service expects', function (string $relay, string $region, string $login, string $host, int $port, string $username): void {
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $relay, $region, $login, $host, $port, $username): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')
            ->call('pickRelay', $relay)
            ->set('smtpPassword', 'khoa-bi-mat')
            ->set('sendDomain', 'CongTy.vn');
        if ($region !== '') {
            $page->set('relayRegion', $region);
        }
        if ($login !== '') {
            $page->set('smtpUsername', $login);
        }
        $page->call('save')->assertHasNoErrors();

        $stored = erlStored();
        expect($stored['relay'])->toBe($relay)
            ->and($stored['send_domain'])->toBe('congty.vn')
            ->and($stored['relay_region'])->toBe($region === '' ? null : $region)
            ->and(SecretCipher::decrypt($stored['smtp']['password']))->toBe('khoa-bi-mat')
            // Postmark's login is the key: it is never written in the clear
            ->and(json_encode($stored))->not->toContain('khoa-bi-mat');

        expect(app(TenantMailer::class)->apply())->toBeTrue()
            ->and(config('mail.mailers.smtp.host'))->toBe($host)
            ->and(config('mail.mailers.smtp.port'))->toBe($port)
            ->and(config('mail.mailers.smtp.encryption'))->toBe('tls')
            ->and(config('mail.mailers.smtp.scheme'))->toBe('smtp')
            ->and(config('mail.mailers.smtp.username'))->toBe($username)
            ->and(config('mail.mailers.smtp.password'))->toBe('khoa-bi-mat')
            // a service's login is no address: mail goes out from its sending domain
            ->and(config('mail.from.address'))->toBe('no-reply@congty.vn');
    });
})->with([
    'SendGrid' => ['sendgrid', '', '', 'smtp.sendgrid.net', 587, 'apikey'],
    'Postmark' => ['postmark', '', '', 'smtp.postmarkapp.com', 587, 'khoa-bi-mat'],
    'Resend' => ['resend', '', '', 'smtp.resend.com', 587, 'resend'],
    'Mailtrap' => ['mailtrap', '', '', 'live.smtp.mailtrap.io', 587, 'api'],
    'Brevo' => ['brevo', '', 'abc@smtp-brevo.com', 'smtp-relay.brevo.com', 587, 'abc@smtp-brevo.com'],
    'Mailgun US' => ['mailgun', 'us', 'postmaster@mg.congty.vn', 'smtp.mailgun.org', 587, 'postmaster@mg.congty.vn'],
    'Mailgun EU' => ['mailgun', 'eu', 'postmaster@mg.congty.vn', 'smtp.eu.mailgun.org', 587, 'postmaster@mg.congty.vn'],
    'Amazon SES' => ['ses', 'eu-west-1', 'AKIAEXAMPLE123456789', 'email-smtp.eu-west-1.amazonaws.com', 587, 'AKIAEXAMPLE123456789'],
]);

it('takes a service\'s server from the relay table, never from what the browser or the store says', function (): void {
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')
            ->call('pickRelay', 'sendgrid')
            ->set('smtpHost', 'may-chu-la.example')->set('smtpPort', 2525)->set('smtpEncryption', 'null')->set('smtpUsername', 'ai-do')
            ->set('smtpPassword', 'SG.khoa')->set('sendDomain', 'congty.vn')
            ->call('save')
            ->assertHasNoErrors();

        expect(erlStored()['smtp'])->toMatchArray(['host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey']);

        // a stored setup that names a service sends to that service's server whatever host it holds
        $cfg = erlStored();
        $cfg['smtp']['host'] = 'may-chu-la.example';
        app(SettingsRepository::class)->put('email', 'config', $cfg);
        expect(app(TenantMailer::class)->apply())->toBeTrue()
            ->and(config('mail.mailers.smtp.host'))->toBe('smtp.sendgrid.net');
    });
});

it('offers only the listed regions, and sends nothing to a region it does not know', function (string $relay, string $region): void {
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $relay, $region): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')
            ->call('pickRelay', $relay)
            ->set('relayRegion', $region)->set('smtpUsername', 'dang-nhap')
            ->set('smtpPassword', 'k')->set('sendDomain', 'congty.vn')
            ->call('save')
            ->assertHasErrors(['relayRegion'])
            ->assertSee('Hãy chọn vùng của tài khoản.');
        expect(erlStored())->toBe([]);

        // a region written into the store by hand never builds a host
        app(SettingsRepository::class)->put('email', 'config', ['provider' => 'other', 'method' => 'smtp_password', 'relay' => $relay,
            'relay_region' => $region, 'send_domain' => 'congty.vn', 'smtp' => ['username' => 'dang-nhap', 'password' => SecretCipher::encrypt('k')]]);
        expect(MailRelay::ready(erlStored()))->toBeFalse()
            ->and(app(TenantMailer::class)->apply())->toBeFalse();
    });
})->with([
    'SES, a made-up region' => ['ses', 'evil.example.com#'],
    'SES, none picked' => ['ses', ''],
    'Mailgun, not US or EU' => ['mailgun', 'ap'],
]);

it('reads a setup saved before the services existed as «Email khác», and it keeps sending as before', function (): void {
    erlStore(['provider' => 'other', 'method' => 'smtp_password', 'from_address' => 'chu@congty.vn',
        'smtp' => ['host' => 'mail.congty.vn', 'port' => 465, 'encryption' => 'ssl', 'username' => 'chu', 'password' => SecretCipher::encrypt('cu')]]);
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        expect(MailRelay::of(erlStored()))->toBe('smtp');

        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->assertSet('relay', 'smtp')
            ->assertSet('smtpHost', 'mail.congty.vn')
            ->assertSee('Không xem lại được')
            ->call('save')
            ->assertHasNoErrors();

        expect(erlStored()['relay'])->toBe('smtp')
            ->and(SecretCipher::decrypt(erlStored()['smtp']['password']))->toBe('cu');
        expect(app(TenantMailer::class)->apply())->toBeTrue()
            ->and(config('mail.mailers.smtp.host'))->toBe('mail.congty.vn')
            ->and(config('mail.mailers.smtp.username'))->toBe('chu')
            ->and(config('mail.from.address'))->toBe('chu@congty.vn');
    });
});

it('keeps the saved key when the field is left blank, and shows it as saved, not readable', function (): void {
    erlSendgrid('SG.cu');
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->assertSet('relay', 'sendgrid')
            ->assertSee('Đã lưu')->assertSee('Không xem lại được — muốn đổi thì bấm Thay.')->assertSee('Thay')
            ->assertDontSee('SG.cu')
            ->set('sendDomain', 'moi.vn')
            ->call('save')
            ->assertHasNoErrors();

        expect(SecretCipher::decrypt(erlStored()['smtp']['password']))->toBe('SG.cu')
            ->and(erlStored()['send_domain'])->toBe('moi.vn');
    });
});

it('asks for a new key when another service is picked, and never carries the old one over', function (string $to, string $message): void {
    erlSendgrid('SG.cu');
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $to, $message): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->call('pickRelay', $to)
            ->assertDontSee('Không xem lại được')
            ->set('sendDomain', 'congty.vn')->set('smtpHost', 'mail.congty.vn')->set('smtpUsername', 'chu')
            ->call('save')
            ->assertHasErrors(['smtpPassword'])
            ->assertSee($message);

        expect(erlStored()['relay'])->toBe('sendgrid');
    });
})->with([
    'to Postmark' => ['postmark', 'Hãy dán khóa lấy từ nhà cung cấp.'],
    'to «Email khác»' => ['smtp', 'Hãy dán mật khẩu ứng dụng.'],
]);

it('says what is wrong with the board\'s words', function (): void {
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')
            ->call('pickRelay', 'brevo')
            ->set('sendDomain', 'khong co dau cham')
            ->call('save')
            ->assertHasErrors(['smtpPassword', 'sendDomain', 'smtpUsername'])
            ->assertSee('Hãy dán khóa lấy từ nhà cung cấp.')
            ->assertSee('Tên miền chưa hợp lệ (ví dụ congty.vn).')
            ->assertSee('Hãy nhập tên đăng nhập SMTP lấy từ nhà cung cấp.');
    });
});

it('refuses a service the list does not offer', function (): void {
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')
            ->call('pickRelay', 'fax')
            ->assertSet('relay', 'sendgrid')
            ->set('relay', 'fax')->set('smtpPassword', 'k')->set('sendDomain', 'congty.vn')
            ->call('save')
            ->assertHasErrors(['relay']);
        expect(erlStored())->toBe([]);
    });
});

it('still refuses a server on the private network for «Email khác»', function (): void {
    app()->instance(MailHostGuard::class, new MailHostGuard(fn (string $host): array => ['10.0.0.5']));
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')
            ->call('pickRelay', 'smtp')
            ->set('smtpHost', 'mail.noi-bo.test')->set('smtpUsername', 'u')->set('smtpPassword', 'p')
            ->call('save')
            ->assertHasErrors(['smtpHost'])
            ->assertSee('Máy chủ này nằm trong mạng nội bộ');
        expect(erlStored())->toBe([]);
    });
});

it('fills in Gmail\'s and Yahoo\'s server from the sending address, only while none is typed', function (string $email, string $typed, string $host, int $port, string $enc): void {
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $email, $typed, $host, $port, $enc): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')
            ->call('pickRelay', 'smtp')
            ->set('smtpHost', $typed)
            ->set('smtpUsername', $email)
            ->assertSet('smtpHost', $host)
            ->assertSet('smtpPort', $port)
            ->assertSet('smtpEncryption', $enc);
    });
})->with([
    'Gmail' => ['ban@gmail.com', '', 'smtp.gmail.com', 587, 'tls'],
    'Googlemail' => ['ban@GoogleMail.com', '', 'smtp.gmail.com', 587, 'tls'],
    'Yahoo' => ['ban@yahoo.com', '', 'smtp.mail.yahoo.com', 465, 'ssl'],
    'a server already typed' => ['ban@gmail.com', 'mail.congty.vn', 'mail.congty.vn', 587, 'tls'],
    'an unknown domain' => ['ban@congty.vn', '', '', 587, 'tls'],
]);

it('points an Outlook or Hotmail address at the Microsoft row instead of filling a server', function (string $email): void {
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $email): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')
            ->call('pickRelay', 'smtp')
            ->set('smtpUsername', $email)
            ->assertSet('smtpHost', '')
            ->assertSee('Outlook / Hotmail không gửi được bằng mật khẩu');
    });
})->with(['ban@outlook.com', 'ban@hotmail.com', 'ban@live.com']);

it('draws the eight services as one radio group in the board\'s order, with the picked one\'s tip', function (): void {
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)->assertDontSee('Chọn dịch vụ');
        $html = $page->set('provider', 'other')->html();
        $group = (string) strstr((string) strstr($html, 'aria-labelledby="em-prov-title"'), 'id="em-panel-sign"', true);

        expect(substr_count($group, 'role="radio"'))->toBe(8)
            ->and(substr_count($group, 'aria-checked="true"'))->toBe(1)
            ->and($html)->toContain('Chọn dịch vụ')
            ->and($html)->toContain('Khuyến nghị: nền tảng dùng nhiều nhất');
        $at = fn (string $s): int|false => strpos($group, $s);
        $names = ['SendGrid', 'Postmark', 'Amazon SES', 'Mailgun', 'Brevo', 'Resend', 'Mailtrap', 'Email khác (nhập máy chủ gửi thư)'];
        foreach (array_slice($names, 1) as $i => $name) {
            expect($at($names[$i].'</span>'))->toBeLessThan($at($name.'</span>'));
        }

        $page->call('pickRelay', 'ses')
            ->assertSee('Khuyến nghị: chi phí thấp nhất')
            ->assertSee('Tên đăng nhập SMTP')->assertSee('Vùng');
    });
});

it('saves, sends a test mail to the member, and says so until a field changes', function (): void {
    Mail::fake();
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')
            ->assertSee('Kiểm tra &amp; lưu kết nối', false)->assertSee('Chưa kiểm tra')
            ->set('smtpPassword', 'SG.moi')->set('sendDomain', 'congty.vn')
            ->call('checkRelay')
            ->assertHasNoErrors()
            ->assertSet('testResult', 'ok')
            ->assertSet('relayChecked', true)
            ->assertSee('Đã kết nối và gửi thử thành công')
            ->assertSee('Kiểm tra lại')
            ->assertSet('toast', 'Kết nối thành công — đã gửi một thư kiểm tra qua nhà cung cấp');
        expect(erlStored()['relay'])->toBe('sendgrid')
            ->and(erlStored())->toHaveKey('checked_at');

        // opened again: still checked
        Livewire::actingAs($admin)->test(EmailSettings::class)->assertSet('relayChecked', true);

        // any change: not checked any more, and saving it drops the old check
        $page->set('sendDomain', 'khac.vn')
            ->assertSet('relayChecked', false)
            ->assertSee('Chưa kiểm tra')
            ->call('save');
        expect(erlStored())->not->toHaveKey('checked_at');
    });
});

it('names the fields still wrong when «Kiểm tra & lưu kết nối» cannot save', function (): void {
    Mail::fake();
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')
            ->call('checkRelay')
            ->assertHasErrors(['smtpPassword', 'sendDomain'])
            ->assertSet('relayChecked', false)
            ->assertSet('toast', 'Còn 2 mục chưa hợp lệ — sửa rồi thử lại')
            ->assertSet('toastTone', 'error');
        expect(erlStored())->toBe([]);
    });
    Mail::assertNothingOutgoing();
});

it('refuses every action of «Chọn dịch vụ» to a member who lost the settings permission', function (string $action): void {
    $admin = erlAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $action): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')->assertSee('Chọn dịch vụ');

        $admin->roles()->firstOrFail()->revokePermissionTo('settings.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        auth()->setUser($admin->fresh());

        match ($action) {
            'pickRelay' => $page->call('pickRelay', 'postmark'),
            'checkRelay' => $page->call('checkRelay'),
            'save' => $page->call('save'),
            'relay' => $page->set('relay', 'smtp'),
        };
        $page->assertForbidden();
        expect(erlStored())->toBe([]);
    });
})->with(['pickRelay', 'checkRelay', 'save', 'relay']);
