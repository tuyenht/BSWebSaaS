<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Support\EmailOtp;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tenant admin login (session). Authenticates the global user against the peppered
 * Argon2id hasher; per-site authority is enforced downstream by EntryPolicy. Failed
 * attempts are rate-limited per email+IP to blunt brute-force / credential stuffing.
 * Tenant 2FA is optional (platform 2FA is mandatory — P0.2).
 */
#[Layout('admin::layouts.auth')]
final class Login extends Component
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public string $email = '';

    public string $password = '';

    public function login(): mixed
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $key = 'admin-login:'.sha1(Str::lower($this->email).'|'.(string) request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->addError('email', __('auth.too_many_attempts', ['seconds' => RateLimiter::availableIn($key)]));

            return null;
        }

        $credentials = ['email' => $this->email, 'password' => $this->password];

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $this->addError('email', __('auth.invalid_credentials'));

            return null;
        }

        RateLimiter::clear($key);

        $user = Auth::getProvider()->retrieveByCredentials($credentials);

        if (! $user instanceof User) {
            $this->addError('email', __('auth.invalid_credentials'));

            return null;
        }

        // Email must be verified before an email+password login completes. We send a fresh
        // verification link (via the resolved mailer) and stop here.
        if (! $user->hasVerifiedEmail()) {
            app(TenantMailer::class)->apply();
            $user->sendEmailVerificationNotification();
            $this->addError('email', __('auth.email_unverified'));

            return null;
        }

        // Second factor is mandatory: the authenticator app when enrolled, otherwise a
        // one-time code emailed to the (verified) address. The user is NOT authenticated
        // here — Auth::login happens only after the challenge passes.
        session()->put('2fa.user_id', $user->getAuthIdentifier());

        if ($user->two_factor_confirmed_at !== null) {
            return $this->redirectRoute(AdminPlane::prefix().'.two-factor.challenge', navigate: false);
        }

        app(EmailOtp::class)->send($user);

        return $this->redirectRoute(AdminPlane::prefix().'.otp.challenge', navigate: false);
    }

    public function render(): View
    {
        $view = view('admin::livewire.login');
        $view->title(__('auth.welcome'));

        return $view;
    }
}
