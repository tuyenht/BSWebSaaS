<?php

use App\Modules\Admin\Livewire\Login;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/*
 * Batch 2c — login guards: a Cloudflare Turnstile bot challenge gates BEFORE the credential check,
 * and a per-IP failure ceiling blunts credential spraying (one IP hitting many accounts) on top of
 * the existing per-email+IP throttle. Turnstile is skipped when unconfigured (dev/test), so the
 * other login tests are unaffected.
 */

it('blocks a valid credential when the Turnstile challenge fails (captcha gates before auth)', function (): void {
    // A configured secret turns verification ON; an empty token then fails without any network call.
    config(['services.turnstile.secret_key' => 'test-secret']);
    $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-horse']);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'correct-horse') // correct — but the missing token must block first
        ->set('turnstileToken', '')
        ->call('login')
        ->assertHasErrors('email')
        ->assertNoRedirect();

    $this->assertGuest();
});

it('lets login proceed when Turnstile is not configured (dev/test default)', function (): void {
    // No secret → verifier skips → an empty token does NOT block; a valid credential reaches 2FA.
    // Fake the notifier so the (queued) OTP send stays out of the way — we only assert the flow.
    Notification::fake();
    $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-horse']);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'correct-horse')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.otp.challenge'));
});

it('blocks even a valid login once the per-IP failure ceiling is hit (spray guard)', function (): void {
    $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-horse']);

    // Saturate the per-IP failure counter as if 30 failed sprays came from this IP (127.0.0.1 in tests).
    $ipKey = 'admin-login-ip:'.sha1('127.0.0.1');
    foreach (range(1, 30) as $ignored) {
        RateLimiter::hit($ipKey, 60);
    }

    // Fresh per-email counter + VALID credentials — but the IP ceiling blocks anyway (would redirect
    // to the 2FA challenge otherwise). assertNoRedirect self-checks that 127.0.0.1 is the request IP.
    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'correct-horse')
        ->call('login')
        ->assertHasErrors('email')
        ->assertNoRedirect();

    $this->assertGuest();
});
