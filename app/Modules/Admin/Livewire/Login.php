<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Support\Cast;
use App\Modules\Admin\Support\EmailOtp;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Services\TurnstileVerifier;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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
    private const int MAX_ATTEMPTS = 5;         // per email+IP — targeted brute force

    private const int MAX_IP_ATTEMPTS = 30;     // failed attempts per IP across all emails — blunts spraying

    private const int DECAY_SECONDS = 60;

    public string $email = '';

    public string $password = '';

    /** Cloudflare Turnstile token, pushed in from the widget's solve callback (see turnstile-field). */
    public string $turnstileToken = '';

    #[On('turnstile-token')]
    public function setTurnstileToken(string $token): void
    {
        $this->turnstileToken = $token;
    }

    /**
     * Cloudflare Turnstile tokens are single-use: a successful verify consumes the token, and a
     * duplicate/expired one is rejected. So on ANY rejected attempt — the challenge itself or a later
     * check — clear the spent token and tell the widget to issue a fresh one, else the user's
     * corrected retry is refused as a duplicate (a ~5-min self-lockout on a mistyped password).
     * No-op when Turnstile is dormant (no widget listens for the event).
     */
    private function refreshChallenge(): void
    {
        $this->turnstileToken = '';
        $this->dispatch('bs-turnstile-reset');
    }

    public function login(): mixed
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $ip = (string) request()->ip();

        // Bot challenge first: a missing/failed Turnstile never reaches the credential check. Enforced
        // ONLY when fully configured (both keys) — the widget renders on the same signal, so login is
        // never gated by a challenge the user cannot see. See TurnstileVerifier::isConfigured().
        $turnstile = app(TurnstileVerifier::class);
        if ($turnstile->isConfigured() && ! $turnstile->verify($this->turnstileToken, $ip)) {
            $this->refreshChallenge();
            Audit::record('auth.login.captcha_failed', ['actor_label' => $this->email]);
            $this->addError('email', __('auth.captcha_failed'));

            return null;
        }

        $key = 'admin-login:'.sha1(Str::lower($this->email).'|'.$ip);
        $ipKey = 'admin-login-ip:'.sha1($ip);

        // Two ceilings: MAX_ATTEMPTS per email+IP (targeted) and MAX_IP_ATTEMPTS failed attempts per IP
        // across every email (spraying). Whichever trips first blocks the attempt.
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS) || RateLimiter::tooManyAttempts($ipKey, self::MAX_IP_ATTEMPTS)) {
            $seconds = max(RateLimiter::availableIn($key), RateLimiter::availableIn($ipKey));
            Audit::record('auth.login.throttled', [
                'actor_label' => $this->email,
                'meta' => ['available_in' => $seconds],
            ]);
            $this->refreshChallenge();
            $this->addError('email', __('auth.too_many_attempts', ['seconds' => $seconds]));

            return null;
        }

        $credentials = ['email' => $this->email, 'password' => $this->password];

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);
            Audit::record('auth.login.failed', [
                'actor_label' => $this->email,
                'meta' => ['reason' => 'invalid_credentials'],
            ]);
            $this->refreshChallenge();
            $this->addError('email', __('auth.invalid_credentials'));

            return null;
        }

        // Clear only the per-email counter on success — the per-IP spray ceiling must NOT reset just
        // because one account authenticated (an attacker holding one valid credential could otherwise
        // reset it between sprays). It decays on its own.
        RateLimiter::clear($key);

        $user = Auth::getProvider()->retrieveByCredentials($credentials);

        if (! $user instanceof User) {
            $this->refreshChallenge();
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
            $this->refreshChallenge();
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
