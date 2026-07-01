<?php

use App\Modules\Admin\Livewire\EmailOtpChallenge;
use App\Modules\Admin\Livewire\Login;
use App\Modules\Admin\Livewire\TwoFactorChallenge;
use App\Modules\Admin\Support\EmailOtp;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * C4 — the auth flow writes an append-only audit trail (design.md §29) for every security-relevant
 * outcome: failed / throttled logins, OTP + 2FA lockouts, email-bomb throttles, and successful
 * sign-ins (with the real client IP now resolved at the edge). Best-effort — a write never blocks
 * the flow. Also pins the new TOTP attempt cap (parity with the email OTP challenge).
 */

beforeEach(function () {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
});

it('records a failed login on invalid credentials', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    expect(AuditLog::query()->where('action', 'auth.login.failed')->exists())->toBeTrue();
});

it('records a throttled login once the brute-force cap trips', function () {
    $user = User::factory()->create();

    // Five wrong attempts arm the limiter; the sixth is refused with the throttle notice.
    foreach (range(1, 6) as $ignored) {
        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login');
    }

    expect(AuditLog::query()->where('action', 'auth.login.throttled')->exists())->toBeTrue();
});

it('records an OTP lockout after too many wrong codes', function () {
    $user = User::factory()->create();
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    foreach (range(1, 6) as $ignored) {
        Livewire::test(EmailOtpChallenge::class)->set('code', '000000')->call('verify');
    }

    expect(AuditLog::query()->where('action', 'auth.otp.locked')->exists())->toBeTrue();
});

it('locks the TOTP challenge after too many wrong codes and audits it (parity with email OTP)', function () {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $user = User::factory()->create();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    foreach (range(1, 6) as $ignored) {
        Livewire::test(TwoFactorChallenge::class)->set('code', '000000')->call('verify')->assertHasErrors('code');
    }

    $this->assertGuest();
    expect(AuditLog::query()->where('action', 'auth.2fa.locked')->exists())->toBeTrue();
});

it('records a successful sign-in with the second factor used', function () {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $user = User::factory()->create();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', (new Google2FA)->getCurrentOtp($secret))
        ->call('verify')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);

    $log = AuditLog::query()->where('action', 'auth.login.succeeded')->firstOrFail();
    expect($log->meta['factor'])->toBe('totp');
});

it('records an OTP send throttle when the hourly cap is hit', function () {
    seedTenancy();
    Notification::fake();
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user) {
        $otp = app(EmailOtp::class);
        foreach (range(1, 6) as $ignored) {
            $otp->send($user);
        }
        expect($otp->send($user))->toBeFalse(); // the 7th within the hour is dropped + audited
    });

    expect(AuditLog::query()->where('action', 'auth.otp.throttled')->exists())->toBeTrue();
});
