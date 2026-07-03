<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Support\Cast;
use App\Modules\Admin\Support\EmailOtp;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Audit\Facades\Audit;
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
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public string $email = '';

    public string $password = '';

    public function login(): mixed
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $key = 'admin-login:'.sha1(Str::lower($this->email).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            Audit::record('auth.login.throttled', [
                'actor_label' => $this->email,
                'meta' => ['available_in' => RateLimiter::availableIn($key)],
            ]);
            $this->addError('email', __('auth.too_many_attempts', ['seconds' => RateLimiter::availableIn($key)]));

            return null;
        }

        $credentials = ['email' => $this->email, 'password' => $this->password];

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            Audit::record('auth.login.failed', [
                'actor_label' => $this->email,
                'meta' => ['reason' => 'invalid_credentials'],
            ]);
            $this->addError('email', __('auth.invalid_credentials'));

            return null;
        }

        RateLimiter::clear($key);

        $user = Auth::getProvider()->retrieveByCredentials($credentials);

        if (! $user instanceof User) {
            $this->addError('email', __('auth.invalid_credentials'));

            return null;
        }

        // Email must be verified before an email+password login completes. Send a fresh verification
        // link, but cap it per hour (same anti email-bomb ceiling as the OTP send) so a valid
        // credential on an unverified address cannot flood the inbox.
        if (! $user->hasVerifiedEmail()) {
            $verifyKey = 'email-verify-send:'.Cast::string($user->getAuthIdentifier());
            if (! RateLimiter::tooManyAttempts($verifyKey, 6)) {
                RateLimiter::hit($verifyKey, 3600);
                app(TenantMailer::class)->apply();
                $user->sendEmailVerificationNotification();
            }
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

        // Only (re)send a code when one isn't already outstanding — a valid credential check
        // must not flood the address with OTP emails (cost / nuisance vector). The existing
        // code stays usable; the cooldown bounds sends per identity regardless of source IP.
        if (app(EmailOtp::class)->canResend($user)) {
            app(EmailOtp::class)->send($user);
        }

        return $this->redirectRoute(AdminPlane::prefix().'.otp.challenge', navigate: false);
    }

    public function render(): View
    {
        $view = view('admin::livewire.login');
        $view->title(__('auth.welcome'));

        return $view;
    }
}
