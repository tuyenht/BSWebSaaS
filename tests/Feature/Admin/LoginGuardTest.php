<?php

use App\Modules\Admin\Livewire\Login;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Http;
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
    // BOTH keys set turns the challenge ON (isConfigured); an empty token then fails with no network
    // call (empty-token short-circuit).
    config(['services.turnstile.site_key' => 'test-site', 'services.turnstile.secret_key' => 'test-secret']);
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

it('does not enforce Turnstile when only one key is configured (no half-config lockout)', function (): void {
    // Secret set but site key missing → isConfigured() false → the challenge is NOT enforced, so login
    // is not locked out by a widget that never rendered. (The mirror case, site-only, is also off.)
    config(['services.turnstile.secret_key' => 'test-secret']);
    Notification::fake();
    $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-horse']);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'correct-horse')
        ->set('turnstileToken', '')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.otp.challenge'));
});

it('resets the single-use Turnstile token after a rejected attempt', function (): void {
    // Turnstile tokens are single-use, so a rejected attempt must clear the token AND tell the widget
    // to issue a fresh one — else the corrected retry is refused as a duplicate (the ~5-min lockout).
    config(['services.turnstile.site_key' => 'test-site', 'services.turnstile.secret_key' => 'test-secret']);
    $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-horse']);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'correct-horse')
        ->set('turnstileToken', '')
        ->call('login')
        ->assertDispatched('bs-turnstile-reset')
        ->assertSet('turnstileToken', '');
});

it('fails open on login when Cloudflare is unreachable (no admin lockout)', function (): void {
    // A Cloudflare outage must not lock every admin out: a PRESENT token that simply can't be verified
    // lets login proceed (password + 2FA + throttle still gate). A missing token still blocks (above).
    config(['services.turnstile.site_key' => 'test-site', 'services.turnstile.secret_key' => 'test-secret']);
    Http::fake(['*' => Http::response('', 503)]); // Cloudflare siteverify unavailable
    Notification::fake();
    $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-horse']);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'correct-horse')
        ->set('turnstileToken', 'present-but-unverifiable')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.otp.challenge'));
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
