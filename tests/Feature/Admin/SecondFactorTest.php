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
use Illuminate\Support\Facades\RateLimiter;
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
    $service->enable($user, $secret, totpCode($secret, -1));
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
    $service->enable($user, $secret, totpCode($secret, -1));
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
    $service->enable($user, $secret, totpCode($secret, -1));
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', '000000')
        ->call('verify')
        ->assertHasErrors('code');

    $this->assertGuest();
});

// ── The cursor goes where the next keystroke belongs ─────────────────────────────────
it('empties the code, drops its error and puts the cursor back after a new code is sent', function (): void {
    seedTenancy();
    Notification::fake();
    $user = User::factory()->create();
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    app(Tenancy::class)->enter(1, function (): void {
        Livewire::test(EmailOtpChallenge::class)
            ->set('code', '158668')
            ->call('verify')
            ->assertHasErrors('code')
            ->assertDispatched('auth-focus', field: 'code')
            ->call('resend')
            ->assertSet('code', '')
            ->assertHasNoErrors()
            ->assertSet('resent', 'sent')
            ->assertDispatched('auth-focus', field: 'code')
            ->set('code', '000000')
            ->call('verify')
            ->assertSet('resent', '')
            ->assertHasErrors('code');
    });
});

it('starts an empty code with the cursor in it when switching to or from a recovery code', function (): void {
    $user = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', '000000')
        ->call('verify')
        ->assertHasErrors('code')
        ->call('switchMode')
        ->assertSet('useRecovery', true)
        ->assertSet('code', '')
        ->assertHasNoErrors()
        ->assertDispatched('auth-focus', field: 'code')
        ->call('switchMode')
        ->assertSet('useRecovery', false);
});

it('sends no new code while the code is locked, and keeps saying how long to wait', function (): void {
    seedTenancy();
    Notification::fake();
    $user = User::factory()->create();
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Livewire::test(EmailOtpChallenge::class);
        foreach (range(1, 6) as $ignored) {
            $page->set('code', '000000')->call('verify');
        }
        expect($page->errors()->first('code'))->toBe(__('auth.otp_locked', ['seconds' => RateLimiter::availableIn('email-otp-verify:'.$user->id)]));

        $page->call('resend')
            ->assertHasErrors('code')
            ->assertSet('resent', '')
            ->assertDispatched('auth-focus', field: 'code');
        Notification::assertNothingSentTo($user);
    });
});

it('shows "please wait" for a resend too soon as a refusal, not as good news', function (): void {
    seedTenancy();
    Notification::fake();
    $user = User::factory()->create();
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    app(Tenancy::class)->enter(1, function (): void {
        Livewire::test(EmailOtpChallenge::class)
            ->call('resend')
            ->assertSeeHtml('auth-alert auth-alert--success')
            ->set('code', '000000')
            ->call('verify')
            ->assertHasErrors('code')
            ->call('resend')
            ->assertSet('resent', 'wait')
            ->assertHasNoErrors()
            ->assertSet('code', '000000')
            ->assertSeeHtml('auth-alert auth-alert--error')
            ->assertDontSeeHtml('auth-alert auth-alert--success');
    });
});

it('keeps the wait on screen when switching code kind while locked', function (): void {
    $user = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    $page = Livewire::test(TwoFactorChallenge::class);
    foreach (range(1, 6) as $ignored) {
        $page->set('code', '000000')->call('verify');
    }
    $page->call('switchMode')->assertSet('useRecovery', true)->assertSet('code', '')->assertHasErrors('code');
});

it('words the resend notice in the language just switched to, in the right colour', function (): void {
    seedTenancy();
    Notification::fake();
    $user = User::factory()->create();
    session(['2fa.user_id' => $user->getAuthIdentifier()]);

    app(Tenancy::class)->enter(1, function (): void {
        $page = Livewire::test(EmailOtpChallenge::class)->call('resend')->call('switchLocale', 'en');
        $page->assertSee(trans('auth.otp_resent', [], 'en'), false)->assertSeeHtml('auth-alert auth-alert--success');
    });
});

it('accepts an emailed code pasted or typed with spaces in it', function (): void {
    seedTenancy();
    Notification::fake();
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $otp = app(EmailOtp::class);
        $otp->send($user);
        $code = null;
        Notification::assertSentTo($user, EmailOtpNotification::class, function (EmailOtpNotification $note) use (&$code): bool {
            $code = (new ReflectionProperty($note, 'code'))->getValue($note);

            return true;
        });

        expect($otp->verify($user, ' '.substr((string) $code, 0, 3).' '.substr((string) $code, 3)."\t"))->toBeTrue();
    });
});
