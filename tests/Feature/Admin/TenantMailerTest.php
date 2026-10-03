<?php

use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * A platform SMTP config without a usable password must NOT count as configured — the mailer
 * falls back (tenant → platform → log) instead of attempting auth or decrypting an empty
 * payload and crashing the send (the forgot-password 500 regression).
 */

beforeEach(function (): void {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    seedTenancy();
});

// The database is shared and never rolled back: a made-up SMTP server left behind would be dialled
// by every later test that really sends a mail.
afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
});

it('is not configured when the platform SMTP password is blank', function (): void {
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'info@example.com',
        'smtp' => ['host' => 'smtp.gmail.com', 'username' => 'info@example.com', 'password' => ''],
    ]);

    app(Tenancy::class)->enter(1, function (): void {
        expect(app(TenantMailer::class)->isConfigured())->toBeFalse();
    });
});

it('is configured when the platform SMTP password is present', function (): void {
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'info@example.com',
        'smtp' => [
            'host' => 'smtp.gmail.com',
            'username' => 'info@example.com',
            'password' => SecretCipher::encrypt('app-password'),
        ],
    ]);

    app(Tenancy::class)->enter(1, function (): void {
        expect(app(TenantMailer::class)->isConfigured())->toBeTrue();
    });
});

/*
 * apply() must pin the Symfony DSN scheme from the config's own encryption/port — Symfony's SMTP
 * transport rejects the legacy "ssl" scheme (UnsupportedSchemeException → a 500 on the login OTP
 * send), and the send must never inherit an invalid global MAIL_SCHEME.
 */

it('pins the scheme to smtps for an SSL / port 465 config', function (): void {
    config(['mail.mailers.smtp.scheme' => 'ssl']); // simulate an invalid global MAIL_SCHEME
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'info@example.com',
        'smtp' => [
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'info@example.com',
            'password' => SecretCipher::encrypt('app-password'),
        ],
    ]);

    app(Tenancy::class)->enter(1, function (): void {
        app(TenantMailer::class)->apply();
        expect(config('mail.mailers.smtp.scheme'))->toBe('smtps')
            ->and(config('mail.mailers.smtp.port'))->toBe(465);
    });
});

it('overrides the From-name with the tenant brand (site name) even on the shared platform SMTP', function (): void {
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_name' => 'BSWebSaaS', // the platform sender name
        'from_address' => 'info@example.com',
        'smtp' => [
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'info@example.com',
            'password' => SecretCipher::encrypt('app-password'),
        ],
    ]);

    app(Tenancy::class)->enter(1, function (): void {
        app(TenantMailer::class)->apply();
        expect(config('mail.from.name'))->toBe('A'); // seedTenancy names site 1 "A"
    });
});

it('uses the smtp scheme for a TLS / port 587 config', function (): void {
    config(['mail.mailers.smtp.scheme' => 'ssl']);
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'info@example.com',
        'smtp' => [
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'info@example.com',
            'password' => SecretCipher::encrypt('app-password'),
        ],
    ]);

    app(Tenancy::class)->enter(1, function (): void {
        app(TenantMailer::class)->apply();
        expect(config('mail.mailers.smtp.scheme'))->toBe('smtp');
    });
});
