<?php

use App\Modules\Admin\Livewire\ForgotPassword;
use App\Modules\Admin\Livewire\ResetPassword;
use App\Modules\Admin\Notifications\ResetPasswordLinkNotification;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

/*
 * Password-reset request hardening. The forgot form is abuse-throttled (per email+IP and per IP)
 * with a uniform reply that leaks neither existence nor the cap; a completed reset is audited and
 * new passwords must be at least 10 chars. Factory emails are random so the suite's no-rollback
 * DB never collides on a fixed address across runs.
 */

it('throttles forgot-password past the hourly cap and keeps every reply uniform', function (): void {
    seedTenancy();
    Notification::fake();
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        // 5 allowed, the 6th trips the per-email cap — but the reply is identical every time.
        foreach (range(1, 6) as $ignored) {
            Livewire::test(ForgotPassword::class)
                ->set('email', $user->email)
                ->call('submit')
                ->assertSet('status', __('auth.reset_link_sent'))
                ->assertHasNoErrors();
        }
    });

    // The capped request is audited (proves the throttle fired) while the user saw only the generic reply.
    expect(AuditLog::query()->where('action', 'auth.password.forgot_throttled')->exists())->toBeTrue();
});

it('audits a completed reset and rejects a password shorter than 10', function (): void {
    seedTenancy();
    fakePwnedVerifier(); // breach check: treat the new password as not compromised
    $user = User::factory()->create();
    $token = Password::createToken($user);

    app(Tenancy::class)->enter(1, function () use ($user, $token): void {
        // Too short → rejected, token untouched.
        Livewire::test(ResetPassword::class)
            ->set('token', $token)->set('email', $user->email)
            ->set('password', 'short1')->set('password_confirmation', 'short1')
            ->call('resetPassword')
            ->assertHasErrors('password');

        // Valid length → resets and audits.
        Livewire::test(ResetPassword::class)
            ->set('token', $token)->set('email', $user->email)
            ->set('password', 'a-fresh-secure-pass')->set('password_confirmation', 'a-fresh-secure-pass')
            ->call('resetPassword')
            ->assertHasNoErrors();
    });

    expect(
        AuditLog::query()->where('action', 'auth.password.reset')->where('actor_id', $user->id)->exists()
    )->toBeTrue();
});

it('queues the reset mail with a request-built link so the reply never blocks on SMTP', function (): void {
    seedTenancy();
    Notification::fake();
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::test(ForgotPassword::class)
            ->set('email', $user->email)
            ->call('submit')
            ->assertSet('status', __('auth.reset_link_sent'));
    });

    Notification::assertSentTo($user, ResetPasswordLinkNotification::class, function (ResetPasswordLinkNotification $notification) use ($user): bool {
        // Queued (constant-time reply + tenant mailer re-applied in the worker) and attributed.
        expect($notification)->toBeInstanceOf(ShouldQueue::class);
        expect($notification->actorId)->toBe($user->id);

        return true;
    });
});
