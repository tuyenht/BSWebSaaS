<?php

use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * A platform SMTP config without a usable password must NOT count as configured — the mailer
 * falls back (tenant → platform → log) instead of attempting auth or decrypting an empty
 * payload and crashing the send (the forgot-password 500 regression).
 */

beforeEach(function () {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    seedTenancy();
});

it('is not configured when the platform SMTP password is blank', function () {
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'info@example.com',
        'smtp' => ['host' => 'smtp.gmail.com', 'username' => 'info@example.com', 'password' => ''],
    ]);

    app(Tenancy::class)->enter(1, function () {
        expect(app(TenantMailer::class)->isConfigured())->toBeFalse();
    });
});

it('is configured when the platform SMTP password is present', function () {
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'info@example.com',
        'smtp' => [
            'host' => 'smtp.gmail.com',
            'username' => 'info@example.com',
            'password' => SecretCipher::encrypt('app-password'),
        ],
    ]);

    app(Tenancy::class)->enter(1, function () {
        expect(app(TenantMailer::class)->isConfigured())->toBeTrue();
    });
});
