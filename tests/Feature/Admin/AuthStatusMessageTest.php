<?php

use App\Modules\Admin\Livewire\Login;
use App\Modules\Admin\Livewire\ResetPassword;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
 * A finished auth step (password reset, email verified) redirects to the login page with a
 * one-shot `status` flash. The login page must show it — otherwise the user never learns the
 * step worked and, typically, starts it over.
 */

it('confirms a completed password reset on the login page', function (): void {
    seedTenancy();
    fakePwnedVerifier(); // breach check: treat the new password as not compromised
    $user = User::factory()->create();
    $token = Password::createToken($user);

    app(Tenancy::class)->enter(1, function () use ($user, $token): void {
        Livewire::test(ResetPassword::class)
            ->set('token', $token)->set('email', $user->email)
            ->set('password', 'a-fresh-secure-pass')->set('password_confirmation', 'a-fresh-secure-pass')
            ->call('resetPassword')
            ->assertRedirect(route('admin.login'));

        Livewire::test(Login::class)
            ->assertSee(__('auth.reset_done'))
            ->assertSeeHtml('role="status"');
    });
});

it('lands a verified email on the login page with its confirmation', function (): void {
    seedTenancy();
    $user = User::factory()->unverified()->create();

    URL::forceRootUrl('http://a.test');
    $link = URL::temporarySignedRoute('admin.verification.verify', now()->addMinutes(30), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->get($link)->assertRedirect(route('admin.login'));

    $this->get('http://a.test/admin/login')->assertSee(__('auth.email_verified'));
    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
});
