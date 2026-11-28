<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Errors\AdminErrorPages;
use App\Modules\Admin\Livewire\Concerns\FocusesAuthField;
use App\Modules\Admin\Livewire\Concerns\SwitchesLocale;
use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Support\EmailOtp;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step two of login for accounts WITHOUT an authenticator app: verify the one-time code
 * emailed by EmailOtp. The user is authenticated only once a valid, unexpired code is
 * entered; attempts are rate-limited and the code is single-use.
 */
#[Layout('admin::layouts.auth')]
final class EmailOtpChallenge extends Component
{
    use FocusesAuthField;
    use SwitchesLocale;

    public string $code = '';

    /** What the last "Gửi lại mã" did: 'sent', 'wait' or '' (none). The view words it, so it follows a language switch. */
    public string $resent = '';

    public function mount(): mixed
    {
        if (! session()->has('2fa.user_id')) {
            return $this->redirectRoute(AdminPlane::prefix().'.login', navigate: false);
        }

        return $this->usesAuthenticator(User::find(session('2fa.user_id'))) ? $this->toAuthenticatorStep() : null;
    }

    /**
     * The emailed code stands in only for people without the authenticator app. Someone who turned
     * the app on is asked for it however they reached this step: a code mailed to the inbox must
     * never replace it (that inbox may be exactly what was taken, e.g. with a Google account).
     */
    private function usesAuthenticator(mixed $user): bool
    {
        return $user instanceof User && $user->two_factor_confirmed_at !== null;
    }

    private function toAuthenticatorStep(): mixed
    {
        return $this->redirectRoute(AdminPlane::prefix().'.two-factor.challenge', navigate: false);
    }

    public function verify(): mixed
    {
        // A new try: "Đã gửi mã mới." no longer applies.
        $this->resent = '';
        $this->validateOrFocus(['code' => 'required|string']);

        $user = User::find(session('2fa.user_id'));
        if (! $user instanceof User) {
            session()->forget('2fa.user_id');

            return $this->redirectRoute(AdminPlane::prefix().'.login', navigate: false);
        }
        if ($this->usesAuthenticator($user)) {
            return $this->toAuthenticatorStep();
        }

        // Defense in depth: never authenticate a non-operator on the platform plane, even if a
        // 2fa.user_id were somehow staged. The login gate already rejects them and host-only session
        // cookies isolate the planes; this enforces the operator-only rule at the point Auth::login
        // happens, so the invariant survives any future change to session-cookie scope.
        if (AdminPlane::isPlatform() && ! app(PlatformAccess::class)->isOperator($user)) {
            session()->forget('2fa.user_id');

            return $this->redirectRoute(AdminPlane::prefix().'.login', navigate: false);
        }

        $rlKey = self::verifyKey($user);
        if (RateLimiter::tooManyAttempts($rlKey, 5)) {
            // Hard cap: burn the code so it can't be brute-forced — a fresh one is required.
            app(EmailOtp::class)->invalidate($user);
            Audit::record('auth.otp.locked', [
                'actor_id' => $user->getAuthIdentifier(),
                'actor_label' => $user->email,
                'meta' => ['factor' => 'email_otp'],
            ]);
            // Say how long, and that a new code is needed (this one is burned): a fresh code sent
            // before the wait is over would be refused all the same.
            $this->addError('code', __('auth.otp_locked', ['seconds' => RateLimiter::availableIn($rlKey)]));
            $this->focusField('code');

            return null;
        }

        if (! app(EmailOtp::class)->verify($user, $this->code)) {
            RateLimiter::hit($rlKey, 600);
            $this->addError('code', __('auth.otp_invalid'));
            $this->focusField('code');

            return null;
        }

        RateLimiter::clear($rlKey);
        // A Google/Facebook sign-in that reached this step: the log says which one.
        $via = Cast::string(session()->pull('2fa.via'));
        session()->forget('2fa.user_id');
        Auth::login($user);
        session()->regenerate();
        Audit::record('auth.login.succeeded', [
            'actor_id' => $user->getAuthIdentifier(),
            'actor_label' => $user->email,
            'meta' => array_filter(['factor' => 'email_otp', 'provider' => $via]),
        ]);

        // Back to the page that sent the visitor to sign in (a protected page, or an invitation
        // link) — the session's intended URL, set on this host only — else the plane's first page.
        return $this->redirectIntended(AdminErrorPages::home(), navigate: false);
    }

    public function resend(): void
    {
        $user = User::find(session('2fa.user_id'));
        if ($this->usesAuthenticator($user)) {
            $this->toAuthenticatorStep();

            return;
        }

        // Locked out after too many wrong codes: a new code would be refused until the wait is over,
        // so none is sent; the wait stays on screen instead of a "sent" that cannot be used.
        if ($user instanceof User && RateLimiter::tooManyAttempts(self::verifyKey($user), 5)) {
            $this->resent = '';
            $this->resetErrorBag();
            $this->addError('code', __('auth.otp_locked', ['seconds' => RateLimiter::availableIn(self::verifyKey($user))]));
            $this->focusField('code');

            return;
        }

        // canResend = the cooldown gate; send() returns false when an hourly abuse cap is hit. Either
        // way the user sees the same "wait" message (no signal about which limit stopped them).
        if ($user instanceof User && app(EmailOtp::class)->canResend($user) && app(EmailOtp::class)->send($user)) {
            // The old code no longer counts: empty the field and drop its error so only the new
            // code's notice shows, then put the cursor there for the code from the new email.
            $this->reset('code');
            $this->resetErrorBag();
            $this->resent = 'sent';
            $this->focusField('code');

            return;
        }

        // One message at a time: the wait replaces the last wrong-code line (the code stays, selected).
        $this->resetErrorBag();
        $this->resent = 'wait';
        $this->focusField('code');
    }

    private static function verifyKey(User $user): string
    {
        return 'email-otp-verify:'.Cast::int($user->getAuthIdentifier());
    }

    public function render(): View
    {
        $view = view('admin::livewire.email-otp-challenge');
        $view->title(__('auth.otp_title'));

        return $view;
    }
}
