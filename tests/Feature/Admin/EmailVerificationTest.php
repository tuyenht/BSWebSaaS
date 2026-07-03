<?php

use App\Modules\Admin\Livewire\Login;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Email+password login is gated on a verified email (default-deny): an unverified account
 * cannot complete login — a fresh verification link is sent and the user stays guest. A
 * verified account proceeds to the mandatory second factor instead.
 */

beforeEach(function (): void {
    // Truncate both settings tables so no stale SMTP ciphertext (encrypted under a different
    // key in the shared dev DB) can cause a DecryptException during TenantMailer::apply().
    DB::connection('pgsql_admin')->statement(
        'truncate site_settings, platform_settings restart identity cascade'
    );
});

it('blocks an unverified account at login and sends a fresh verification link', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('caps the verification-link resend per hour (valid creds clear the login limiter, so cap it here)', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    foreach (range(1, 7) as $ignored) {
        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');
    }

    // 6 links sent; the 7th within the hour is dropped (the login rate-limit is cleared on valid creds).
    Notification::assertSentToTimes($user, VerifyEmail::class, 6);
});

it('lets a verified account proceed to the mandatory second factor', function (): void {
    $user = User::factory()->create(); // verified by default

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.otp.challenge'));

    $this->assertGuest();
    expect(session('2fa.user_id'))->not->toBeNull();
});
