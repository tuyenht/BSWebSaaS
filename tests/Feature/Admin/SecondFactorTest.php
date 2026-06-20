<?php

use App\Modules\Admin\Livewire\EmailOtpChallenge;
use App\Modules\Admin\Livewire\TwoFactorChallenge;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * P0.2 — the mandatory second factor after email+password. A user with TOTP enrolled is
 * sent to the authenticator challenge; everyone else gets an emailed one-time code. The
 * user is authenticated ONLY once the challenge passes; the pending id lives in the session.
 */

// ── Email OTP (default fallback — no authenticator app) ───────────────────────────
function seedOtp(User $user, string $code): void
{
    Cache::put('email-otp:'.$user->getAuthIdentifier(), [
        'hash' => hash('sha256', $code),
        'sent_at' => time(),
    ], 600);
}

it('redirects the OTP challenge to login without a pending 2FA session', function () {
    Livewire::test(EmailOtpChallenge::class)->assertRedirect(route('admin.login'));
});

it('logs the user in after a correct email OTP and clears the pending session', function () {
    $user = User::factory()->create();
    session(['2fa.user_id' => $user->getAuthIdentifier()]);
    seedOtp($user, '123456');

    Livewire::test(EmailOtpChallenge::class)
        ->set('code', '123456')
        ->call('verify')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(session('2fa.user_id'))->toBeNull()
        ->and(Cache::get('email-otp:'.$user->getAuthIdentifier()))->toBeNull();
});

it('rejects a wrong email OTP and stays guest', function () {
    $user = User::factory()->create();
    session(['2fa.user_id' => $user->getAuthIdentifier()]);
    seedOtp($user, '123456');

    Livewire::test(EmailOtpChallenge::class)
        ->set('code', '999999')
        ->call('verify')
        ->assertHasErrors('code');

    $this->assertGuest();
});

it('burns the code and locks out after too many wrong OTP attempts', function () {
    $user = User::factory()->create();
    session(['2fa.user_id' => $user->getAuthIdentifier()]);
    seedOtp($user, '123456');

    // 5 wrong attempts arm the per-user cap.
    foreach (range(1, 5) as $ignored) {
        Livewire::test(EmailOtpChallenge::class)->set('code', '000000')->call('verify')->assertHasErrors('code');
    }

    // 6th attempt is capped — the code is burned even though it is the CORRECT one.
    Livewire::test(EmailOtpChallenge::class)
        ->set('code', '123456')
        ->call('verify')
        ->assertHasErrors('code');

    $this->assertGuest();
    expect(Cache::get('email-otp:'.$user->getAuthIdentifier()))->toBeNull();
});

// ── TOTP (authenticator app) ───────────────────────────────────────────────────────
it('redirects the TOTP challenge to login without a pending 2FA session', function () {
    Livewire::test(TwoFactorChallenge::class)->assertRedirect(route('admin.login'));
});

it('logs the user in after a valid TOTP code', function () {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $user = User::factory()->create();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', (new Google2FA)->getCurrentOtp($secret))
        ->call('verify')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(session('2fa.user_id'))->toBeNull();
});

it('logs the user in with a single-use recovery code', function () {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $user = User::factory()->create();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));
    $recovery = $user->refresh()->two_factor_recovery_codes[0];
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('useRecovery', true)
        ->set('code', $recovery)
        ->call('verify')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('rejects an invalid TOTP code and stays guest', function () {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $user = User::factory()->create();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', '000000')
        ->call('verify')
        ->assertHasErrors('code');

    $this->assertGuest();
});
