<?php

declare(strict_types=1);

use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * Regression: under Octane, config() is a warmed singleton shared across requests in the
 * same worker. apply() must leave the mailer in a deterministic state after every call:
 * — resolved config found → smtp or admin_xoauth2
 * — no config found       → config('mail.default') === 'log', NOT the previous tenant's smtp
 *
 * Simulates worker reuse on one process (no process restart between the two sites).
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate site_settings, platform_settings restart identity cascade'
    );
    seedTenancy(); // creates sites 1 (a.test) and 2 (b.test)
});

it('forces log mailer for a tenant with no email config, never leaking the previous smtp', function (): void {
    // Seed site A's SMTP override so it is picked up by resolve() inside site-1 context.
    app(Tenancy::class)->enter(1, function (): void {
        app(SettingsRepository::class)->put('email', 'config', [
            'method' => 'smtp_password',
            'from_address' => 'mailer@site-a.com',
            'smtp' => [
                'host' => 'smtp.site-a.com',
                'port' => 587,
                'username' => 'mailer@site-a.com',
                'password' => SecretCipher::encrypt('secret-a'),
            ],
        ]);
    });

    // Request 1 (site A): apply() configures smtp with A's credentials.
    $resultA = null;
    app(Tenancy::class)->enter(1, function () use (&$resultA): void {
        $resultA = app(TenantMailer::class)->apply();
    });

    expect($resultA)->toBeTrue()
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.site-a.com')
        ->and(config('mail.mailers.smtp.password'))->toBe('secret-a');

    // Request 2 (site B, same worker): no email override, no platform default.
    // apply() MUST reset to 'log' — site A's smtp config must NOT bleed through.
    $resultB = null;
    app(Tenancy::class)->enter(2, function () use (&$resultB): void {
        $resultB = app(TenantMailer::class)->apply();
    });

    expect($resultB)->toBeFalse()
        ->and(config('mail.default'))->toBe('log'); // not 'smtp'
});
