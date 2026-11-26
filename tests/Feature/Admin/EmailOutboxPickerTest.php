<?php

use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The «Email» page's «Hộp thư gửi đi»: a website picks the platform's shared outbox, a signed-in
 * mailbox (Google, Microsoft) or its own provider, and the card above says whether that pick sends
 * and where mail goes meanwhile. Picking the shared outbox keeps what the website set up for its
 * own, so it can pick it again, but mail then leaves through the platform with the website's name.
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

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
});

function eopAdmin(): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

function eopPlatformOutbox(): void
{
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'no-reply@nen-tang.test',
        'smtp' => ['host' => 'smtp.nen-tang.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'relay', 'password' => SecretCipher::encrypt('pw')],
    ]);
}

/** @param array<string, mixed> $cfg */
function eopOwnSetup(array $cfg): void
{
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', $cfg));
}

it('reads the outbox of a setup saved before the choice existed by what it holds', function (array $cfg, string $outbox): void {
    expect(EmailSettings::outboxOf($cfg))->toBe($outbox);
})->with([
    'nothing of its own' => [['method' => 'smtp_password', 'from_name' => 'An Vinh'], 'platform'],
    'its own mail server' => [['method' => 'smtp_password', 'smtp' => ['host' => 'smtp.example.net']], 'other'],
    'a Google mailbox' => [['method' => 'oauth2', 'oauth' => ['provider' => 'google']], 'google'],
    'a Microsoft mailbox' => [['method' => 'oauth2', 'oauth' => ['provider' => 'microsoft']], 'microsoft'],
    'a Yahoo mailbox' => [['method' => 'oauth2', 'oauth' => ['provider' => 'yahoo']], 'yahoo'],
    'a stored pick wins' => [['provider' => 'platform', 'method' => 'smtp_password', 'smtp' => ['host' => 'smtp.example.net']], 'platform'],
    'an unknown stored pick is read by content' => [['provider' => 'fax', 'method' => 'oauth2', 'oauth' => ['provider' => 'microsoft']], 'microsoft'],
]);

it('sends through the platform with the website name once the shared outbox is picked, keeping the own server stored', function (): void {
    eopPlatformOutbox();
    eopOwnSetup(['method' => 'smtp_password', 'from_name' => 'Cơ Khí An Vinh',
        'smtp' => ['host' => 'smtp.example.net', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'password' => SecretCipher::encrypt('own')]]);
    $admin = eopAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->assertSet('provider', 'other')
            ->set('provider', 'platform')
            ->call('save')
            ->assertHasNoErrors();

        $stored = app(SettingsRepository::class)->get('email');
        expect($stored['provider'])->toBe('platform')
            ->and($stored['smtp']['host'])->toBe('smtp.example.net');

        expect(app(TenantMailer::class)->apply())->toBeTrue()
            ->and(config('mail.mailers.smtp.host'))->toBe('smtp.nen-tang.test')
            ->and(config('mail.from.name'))->toBe('Cơ Khí An Vinh');

        // and back: the own server sends again without typing it twice
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->assertSet('provider', 'platform')
            ->set('provider', 'other')
            ->call('save')
            ->assertHasNoErrors();
        expect(app(TenantMailer::class)->apply())->toBeTrue()
            ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.net');
    });
});

it('says the picked outbox sends, or that it is not finished and where mail goes meanwhile', function (?array $own, bool $platform, string $title, ?string $meta): void {
    if ($platform) {
        eopPlatformOutbox();
    }
    if ($own !== null) {
        eopOwnSetup($own);
    }
    $admin = eopAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $title, $meta): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)->assertSee($title);
        if ($meta !== null) {
            $page->assertSee($meta);
        }
    });
})->with([
    'shared outbox that works' => [null, true, 'Đang gửi qua Hộp thư dùng chung của nền tảng', null],
    'shared outbox the platform has not set up' => [null, false, 'Chưa gửi được — Hộp thư dùng chung của nền tảng chưa hoàn tất', 'Hiện chưa có thư nào gửi đi được.'],
    'Google picked, not signed in' => [['provider' => 'google', 'method' => 'oauth2', 'oauth' => ['provider' => 'google', 'client_id' => 'cid']], true,
        'Chưa gửi được — Gmail / Google Workspace chưa hoàn tất', 'Trong lúc chờ, thư vẫn gửi qua hộp thư dùng chung của nền tảng.'],
    'Google signed in' => [['provider' => 'google', 'method' => 'oauth2', 'oauth' => ['provider' => 'google', 'client_id' => 'cid', 'refresh_token' => 'x', 'email' => 'hop@anvinh.vn']], true,
        'Đang gửi qua Gmail / Google Workspace', 'hop@anvinh.vn'],
    'own server half set up, nothing else sends' => [['provider' => 'other', 'method' => 'smtp_password', 'smtp' => ['host' => 'smtp.example.net', 'username' => 'u']], false,
        'Chưa gửi được — nhà cung cấp riêng chưa hoàn tất', 'Hiện chưa có thư nào gửi đi được.'],
    'own server set up' => [['provider' => 'other', 'method' => 'smtp_password', 'smtp' => ['host' => 'smtp.example.net', 'username' => 'u', 'password' => 'enc']], false,
        'Đang gửi qua nhà cung cấp riêng', 'Gửi qua máy chủ gửi thư riêng'],
]);

it('draws the four outboxes as one radio group, the shared one first and marked default', function (): void {
    eopPlatformOutbox();
    $admin = eopAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $html = Livewire::actingAs($admin)->test(EmailSettings::class)->html();
        // The outbox group only (the signature tab's colours are a radio group of their own).
        $group = (string) strstr((string) strstr($html, 'aria-labelledby="em-outbox-title"'), 'id="em-panel-sign"', true);

        expect(substr_count($group, 'role="radio"'))->toBe(4)
            ->and(substr_count($group, 'aria-checked="true"'))->toBe(1)
            ->and($html)->toContain('role="radiogroup" aria-labelledby="em-outbox-title"')
            ->and($html)->not->toContain('Yahoo Mail')
            ->and($html)->toContain('no-reply@nen-tang.test');

        // the order on the board, with the default badge on the shared outbox
        $at = fn (string $s): int|false => strpos($html, $s);
        expect($at('Hộp thư dùng chung của nền tảng</b>'))->toBeLessThan($at('Gmail / Google Workspace</b>'))
            ->and($at('Gmail / Google Workspace</b>'))->toBeLessThan($at('Outlook / Microsoft 365</b>'))
            ->and($at('Outlook / Microsoft 365</b>'))->toBeLessThan($at('Nhà cung cấp khác</b>'));
    });
});

it('keeps the Yahoo row only for a website already signed in with Yahoo', function (): void {
    eopOwnSetup(['method' => 'oauth2', 'oauth' => ['provider' => 'yahoo', 'client_id' => 'cid', 'refresh_token' => 'x', 'email' => 'a@yahoo.com']]);
    $admin = eopAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->assertSet('provider', 'yahoo')
            ->assertSee('Yahoo Mail')
            ->call('save')
            ->assertHasNoErrors();
    });
});

it('refuses an outbox that is not offered', function (string $pick): void {
    $admin = eopAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $pick): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', $pick)
            ->call('save')
            ->assertHasErrors(['provider']);
    });
})->with(['yahoo', 'fax', '']);

it('shows the sign-in box under a picked mailbox, and the mail-server form only for its own provider', function (): void {
    $admin = eopAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class);
        $page->assertDontSee(__('admin.smtp_host'))->assertDontSee(__('admin.client_id'));

        $page->set('provider', 'microsoft')
            ->assertSet('method', 'oauth2')->assertSet('oauthProvider', 'microsoft')
            ->assertSee(__('admin.client_id'))->assertSee('Kết nối Microsoft')
            ->assertDontSee(__('admin.smtp_host'));

        // «Nhà cung cấp khác» starts on the board's first service; «Email khác» shows the server fields
        $page->set('provider', 'other')
            ->assertSet('method', 'smtp_password')
            ->assertSee('Chọn dịch vụ')->assertDontSee(__('admin.smtp_host'))->assertDontSee(__('admin.client_id'));
        $page->call('pickRelay', 'smtp')
            ->assertSee(__('admin.smtp_host'))->assertDontSee(__('admin.client_id'));
    });
});

it('forgets the signed-in mailbox on «Ngắt kết nối», and mail goes back to the shared outbox', function (): void {
    eopPlatformOutbox();
    eopOwnSetup(['provider' => 'google', 'method' => 'oauth2',
        'oauth' => ['provider' => 'google', 'client_id' => 'cid', 'client_secret' => SecretCipher::encrypt('s'), 'refresh_token' => SecretCipher::encrypt('r'), 'email' => 'hop@anvinh.vn']]);
    $admin = eopAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->assertSee('Ngắt kết nối')
            ->call('disconnect')
            ->assertSee('Chưa gửi được — Gmail / Google Workspace chưa hoàn tất');

        $oauth = app(SettingsRepository::class)->get('email')['oauth'];
        expect($oauth)->not->toHaveKey('refresh_token')
            ->and($oauth)->not->toHaveKey('email')
            ->and($oauth['client_id'])->toBe('cid');
        expect(app(TenantMailer::class)->resolve()['smtp']['host'] ?? null)->toBe('smtp.nen-tang.test');
    });
});

it('never carries one provider\'s sign-in over to another mailbox', function (): void {
    eopPlatformOutbox();
    eopOwnSetup(['provider' => 'google', 'method' => 'oauth2',
        'oauth' => ['provider' => 'google', 'client_id' => 'cid', 'client_secret' => SecretCipher::encrypt('s'), 'refresh_token' => SecretCipher::encrypt('r'), 'email' => 'hop@anvinh.vn']]);
    $admin = eopAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'microsoft')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Chưa gửi được — Outlook / Microsoft 365 chưa hoàn tất')
            ->assertDontSee('hop@anvinh.vn');

        $oauth = app(SettingsRepository::class)->get('email')['oauth'];
        expect($oauth['provider'])->toBe('microsoft')
            ->and($oauth)->not->toHaveKey('refresh_token');
        // nothing of its own sends, so mail leaves through the platform rather than failing at Microsoft
        expect(app(TenantMailer::class)->resolve()['smtp']['host'] ?? null)->toBe('smtp.nen-tang.test');
    });
});

it('keeps unsaved edits counted after «Ngắt kết nối»', function (): void {
    eopOwnSetup(['provider' => 'google', 'method' => 'oauth2',
        'oauth' => ['provider' => 'google', 'client_id' => 'cid', 'refresh_token' => SecretCipher::encrypt('r'), 'email' => 'hop@anvinh.vn']]);
    $admin = eopAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $page = Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('fromName', 'Tên chưa lưu')
            ->call('disconnect');

        expect($page->viewData('changes'))->toBe(1);
        expect(app(SettingsRepository::class)->get('email')['from_name'] ?? null)->toBeNull();
    });
});

it('signs in only with the picked mailbox, whatever provider the request names', function (): void {
    $admin = eopAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'platform')
            ->set('oauthProvider', 'yahoo')
            ->call('connect')
            ->assertStatus(422);

        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'microsoft')
            ->set('oauthClientId', 'cid')
            ->set('oauthProvider', 'yahoo')
            ->call('connect')
            ->assertRedirect(route('admin.oauth.start', ['provider' => 'microsoft', 'purpose' => 'email']));
    });
});

it('does not call the shared outbox working when the platform has no password', function (): void {
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'smtp' => ['host' => 'smtp.nen-tang.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'relay', 'password' => null],
    ]);
    $admin = eopAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->assertSee('Chưa gửi được — Hộp thư dùng chung của nền tảng chưa hoàn tất');
    });
});

it('sends a setup saved before the choice existed through its own server, as before', function (): void {
    eopPlatformOutbox();
    eopOwnSetup(['method' => 'smtp_password',
        'smtp' => ['host' => 'smtp.example.net', 'port' => 587, 'encryption' => 'tls', 'username' => 'u', 'password' => SecretCipher::encrypt('own')]]);

    app(Tenancy::class)->enter(1, function (): void {
        expect(app(TenantMailer::class)->apply())->toBeTrue()
            ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.net');
    });
});
