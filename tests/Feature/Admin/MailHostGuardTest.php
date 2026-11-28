<?php

use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\MailHostRefused;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;

/*
 * A site admin types the SMTP server and port, and «Gửi thử» connects to them. Neither may lead to
 * the platform's own private network (database pool, cache, cloud metadata) or to a port that is not
 * a mail port, or the page becomes a way to probe what answers inside. Refused when saving, and
 * again right before a send for a sender saved earlier or whose name now resolves elsewhere.
 */

beforeEach(function (): void {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    // names resolve here without touching real DNS: "valkey" is a service on the private network
    app()->instance(MailHostGuard::class, new MailHostGuard(fn (string $host): array => match ($host) {
        'valkey' => ['172.18.0.5'],
        'localhost' => ['127.0.0.1'],
        'mail.example.net' => ['93.184.216.34'],
        'rebound.example.net' => ['169.254.169.254'],
        default => [],
    }));
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
});

it('tells a private or loopback address from a public one, by literal or by name', function (string $host, ?string $refusal): void {
    expect(app(MailHostGuard::class)->refusal($host))->toBe($refusal);
})->with([
    'loopback' => ['127.0.0.1', MailHostGuard::PRIVATE],
    'private range' => ['10.0.0.5', MailHostGuard::PRIVATE],
    'cloud metadata' => ['169.254.169.254', MailHostGuard::PRIVATE],
    'carrier-grade NAT' => ['100.64.0.1', MailHostGuard::PRIVATE],
    'IPv6 loopback in brackets' => ['[::1]', MailHostGuard::PRIVATE],
    'IPv4 inside IPv6' => ['::ffff:127.0.0.1', MailHostGuard::PRIVATE],
    'a private IPv4 inside NAT64' => ['64:ff9b::a00:5', MailHostGuard::PRIVATE],
    'loopback inside IPv4-compatible IPv6' => ['::7f00:1', MailHostGuard::PRIVATE],
    'cloud metadata inside IPv4-compatible IPv6' => ['::a9fe:a9fe', MailHostGuard::PRIVATE],
    'IPv6 site-local' => ['fec0::1', MailHostGuard::PRIVATE],
    'IPv6 multicast' => ['ff02::1', MailHostGuard::PRIVATE],
    'a public IPv6 address' => ['2606:4700:4700::1111', null],
    'a service name on the private network' => ['valkey', MailHostGuard::PRIVATE],
    'a public address' => ['93.184.216.34', null],
    'a name with a public address' => ['mail.example.net', null],
    'a name that does not resolve' => ['nowhere.example.net', MailHostGuard::UNRESOLVABLE],
    'empty (the required rule speaks)' => ['', null],
]);

// The connection reads these as IPv4 addresses whatever DNS says (proved against a listener on
// 127.0.0.1 in review): refused by their shape, before any lookup.
it('refuses a name whose last label is a number, in any spelling', function (string $host): void {
    expect((new MailHostGuard(fn (): array => ['93.184.216.34']))->refusal($host))->toBe(MailHostGuard::PRIVATE);
})->with(['2130706433', '127.1', '0x7f.1', '0x7f000001', '0x7f.0.0.1', '0x7f.0x0.0x0.0x1', '0177.0.0.1', '0xa.0.0.5', 'mail.example.123']);

it('finds the names only the system resolver knows (/etc/hosts)', function (): void {
    expect((new MailHostGuard)->refusal('localhost'))->toBe(MailHostGuard::PRIVATE);
});

it('accepts only mail ports', function (int $port, bool $allowed): void {
    expect(app(MailHostGuard::class)->allowsPort($port))->toBe($allowed);
})->with([[587, true], [465, true], [25, true], [2525, true], [2587, true], [6379, false], [5432, false], [80, false]]);

function mhgAdmin(): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

it('refuses to save a server on the private network, in plain words, and stores nothing', function (string $host): void {
    $admin = mhgAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $host): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')->set('relay', 'smtp')->set('smtpHost', $host)->set('smtpPort', 587)
            ->set('smtpUsername', 'u')->set('smtpPassword', 'p')
            ->call('save')
            ->assertHasErrors(['smtpHost'])
            ->assertSee('Máy chủ này nằm trong mạng nội bộ');

        expect(SiteSetting::query()->where('group_name', 'email')->exists())->toBeFalse();
    });
})->with(['valkey', '127.0.0.1', 'localhost', '0x7f.1']);

it('refuses to save a server name that does not exist, saying to check it', function (): void {
    $admin = mhgAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')->set('relay', 'smtp')->set('smtpHost', 'smtp.gmial.com')->set('smtpPort', 587)
            ->set('smtpUsername', 'u')->set('smtpPassword', 'p')
            ->call('save')
            ->assertHasErrors(['smtpHost'])
            ->assertSee('Không tìm thấy máy chủ này');
    });
});

it('lets the platform page keep a relay on the private network, on any port', function (): void {
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));

    app(PlatformAccess::class)->onPlatform(function () use ($operator): void {
        Livewire::actingAs($operator->fresh())->test(EmailSettings::class, ['scope' => 'platform'])
            ->set('method', 'smtp_password')->set('smtpHost', 'valkey')->set('smtpPort', 1025)
            ->set('smtpUsername', 'u')->set('smtpPassword', 'p')
            ->call('save')
            ->assertHasNoErrors();
    });

    expect(app(PlatformSettings::class)->get('email')['smtp']['host'] ?? null)->toBe('valkey');
});

it('refuses a port that is not a mail port and names the ones allowed', function (): void {
    $admin = mhgAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')->set('relay', 'smtp')->set('smtpHost', 'mail.example.net')->set('smtpPort', 6379)
            ->set('smtpUsername', 'u')->set('smtpPassword', 'p')
            ->call('save')
            ->assertHasErrors(['smtpPort'])
            ->assertSee('25, 465, 587, 2525, 2587');
    });
});

it('still saves a public server on a mail port', function (): void {
    $admin = mhgAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('provider', 'other')->set('relay', 'smtp')->set('smtpHost', 'mail.example.net')->set('smtpPort', 465)
            ->set('smtpUsername', 'u')->set('smtpPassword', 'p')
            ->call('save')
            ->assertHasNoErrors();

        expect(app(SettingsRepository::class)->get('email')['smtp']['host'] ?? null)->toBe('mail.example.net');
    });
});

it('sends nothing through a stored sender that leads inside, even one saved before the check', function (string $host, int $port): void {
    app(Tenancy::class)->enter(1, function () use ($host, $port): void {
        app(SettingsRepository::class)->put('email', 'config', [
            'method' => 'smtp_password',
            'from_address' => 'info@example.net',
            'smtp' => ['host' => $host, 'port' => $port, 'username' => 'u', 'password' => SecretCipher::encrypt('p')],
        ]);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'left.from.before']);

        Exceptions::fake();

        expect(app(TenantMailer::class)->apply())->toBeFalse()
            ->and(config('mail.default'))->toBe('log')
            ->and(config('mail.mailers.smtp.host'))->toBe('left.from.before');   // nothing pointed at it

        // held back, never unseen
        Exceptions::assertReported(fn (MailHostRefused $e): bool => str_contains($e->getMessage(), $host) && $e->context() === ['site_id' => 1]);
    });
})->with([
    'a private service' => ['valkey', 587],
    'a name that now resolves to cloud metadata' => ['rebound.example.net', 587],
    'a public server on a non-mail port' => ['mail.example.net', 6379],
]);

it('leaves the platform sender alone: the operator sets it, not a site', function (): void {
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'info@example.net',
        'smtp' => ['host' => 'valkey', 'port' => 1025, 'username' => 'u', 'password' => SecretCipher::encrypt('p')],
    ]);

    app(Tenancy::class)->enter(1, function (): void {
        expect(app(TenantMailer::class)->apply())->toBeTrue()
            ->and(config('mail.mailers.smtp.host'))->toBe('valkey');
    });
});

it('leaves a name that does not resolve right now to the connection, so queued mail is tried again', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        app(SettingsRepository::class)->put('email', 'config', [
            'method' => 'smtp_password',
            'from_address' => 'info@example.net',
            'smtp' => ['host' => 'nowhere.example.net', 'port' => 587, 'username' => 'u', 'password' => SecretCipher::encrypt('p')],
        ]);

        expect(app(TenantMailer::class)->apply())->toBeTrue()
            ->and(config('mail.default'))->toBe('smtp')
            ->and(config('mail.mailers.smtp.host'))->toBe('nowhere.example.net');
    });
});

it('keeps sending through a public server on a mail port', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        app(SettingsRepository::class)->put('email', 'config', [
            'method' => 'smtp_password',
            'from_address' => 'info@example.net',
            'smtp' => ['host' => 'mail.example.net', 'port' => 587, 'username' => 'u', 'password' => SecretCipher::encrypt('p')],
        ]);

        expect(app(TenantMailer::class)->apply())->toBeTrue()
            ->and(config('mail.default'))->toBe('smtp')
            ->and(config('mail.mailers.smtp.host'))->toBe('mail.example.net');
    });
});
