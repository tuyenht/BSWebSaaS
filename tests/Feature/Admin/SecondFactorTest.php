<?php

use App\Modules\Admin\Livewire\EmailOtpChallenge;
use App\Modules\Admin\Livewire\TwoFactorChallenge;
use App\Modules\Admin\Notifications\EmailOtpNotification;
use App\Modules\Admin\Support\EmailOtp;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * P0.2 — the mandatory second factor after email+password. A user with TOTP enrolled is
 * sent to the authenticator challenge; everyone else gets an emailed one-time code. The
 * user is authenticated ONLY once the challenge passes; the pending id lives in the session.
 */

beforeEach(function (): void {
    // Keep tenant + platform settings clean between order-independent runs so no leftover SMTP
    // ciphertext (encrypted under another test's key) can trip a later mailer resolve.
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
});

// ── Email OTP challenge ──────────────────────────────────────────────────────────────
it('redirects the OTP challenge to login without a pending 2FA session', function (): void {
    Livewire::test(EmailOtpChallenge::class)->assertRedirect(route('admin.login'));
});

it('rejects a wrong OTP and stays guest', function (): void {
    $user = User::factory()->create();
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    Livewire::test(EmailOtpChallenge::class)
        ->set('code', '000000')
        ->call('verify')
        ->assertHasErrors('code');

    $this->assertGuest();
});

it('locks the OTP challenge out after too many failed attempts', function (): void {
    $user = User::factory()->create();
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    // No valid code is outstanding, so every attempt fails; the 6th trips the per-user cap.
    foreach (range(1, 6) as $ignored) {
        Livewire::test(EmailOtpChallenge::class)->set('code', '000000')->call('verify')->assertHasErrors('code');
    }

    $this->assertGuest();
});

it('caps OTP sends per account per hour (anti email-bomb)', function (): void {
    seedTenancy();
    Notification::fake();
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $otp = app(EmailOtp::class);
        foreach (range(1, 6) as $ignored) {
            expect($otp->send($user))->toBeTrue();
        }
        expect($otp->send($user))->toBeFalse(); // the 7th within the hour is dropped, not emailed
    });
});

it('does not accept one user email OTP for another user (no cross-user 2FA bypass)', function (): void {
    seedTenancy();
    Notification::fake();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($userA, $userB): void {
        $otp = app(EmailOtp::class);
        $otp->send($userA);

        // The plaintext code A was emailed only lives in the notification — pull it out to replay.
        $code = null;
        Notification::assertSentTo($userA, EmailOtpNotification::class, function (EmailOtpNotification $note) use (&$code): bool {
            $code = (new ReflectionProperty($note, 'code'))->getValue($note);

            return true;
        });

        // Regression: every account once shared the cache slot 'email-otp:' (Cast::string dropped the
        // integer id), so ONE user's code logged in ANY account. Each user now has its own slot.
        expect($otp->verify($userB, (string) $code))->toBeFalse(); // A's code must NOT verify B
        expect($otp->verify($userA, (string) $code))->toBeTrue();  // A's own code still works
    });
});

it('gives each user its own OTP send cooldown (fixes only-one-email)', function (): void {
    seedTenancy();
    Notification::fake();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($userA, $userB): void {
        $otp = app(EmailOtp::class);
        $otp->send($userA);

        // Before the fix A's send sat in the shared slot, so B's login found the cooldown active and
        // skipped emailing B a code. Independent slots mean B still gets its own.
        expect($otp->canResend($userB))->toBeTrue();
    });
});

// ── TOTP (authenticator app) ───────────────────────────────────────────────────────
it('redirects the TOTP challenge to login without a pending 2FA session', function (): void {
    Livewire::test(TwoFactorChallenge::class)->assertRedirect(route('admin.login'));
});

it('logs the user in after a valid TOTP code', function (): void {
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

it('logs the user in with a single-use recovery code', function (): void {
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

it('rejects an invalid TOTP code and stays guest', function (): void {
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
