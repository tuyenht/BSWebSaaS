<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\SwitchesLocale;
use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Support\Cast;
use App\Modules\Admin\Support\EmailOtp;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
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
    use SwitchesLocale;

    public string $code = '';

    public string $sentStatus = '';

    public function mount(): mixed
    {
        if (! session()->has('2fa.user_id')) {
            return $this->redirectRoute(AdminPlane::prefix().'.login', navigate: false);
        }

        return null;
    }

    public function verify(): mixed
    {
        $this->validate(['code' => 'required|string']);

        $user = User::find(session('2fa.user_id'));
        if (! $user instanceof User) {
            session()->forget('2fa.user_id');

            return $this->redirectRoute(AdminPlane::prefix().'.login', navigate: false);
        }

        // Defense in depth: never authenticate a non-operator on the platform plane, even if a
        // 2fa.user_id were somehow staged. The login gate already rejects them and host-only session
        // cookies isolate the planes; this enforces the operator-only rule at the point Auth::login
        // happens, so the invariant survives any future change to session-cookie scope.
        if (AdminPlane::isPlatform() && ! app(PlatformAccess::class)->isOperator($user)) {
            session()->forget('2fa.user_id');

            return $this->redirectRoute(AdminPlane::prefix().'.login', navigate: false);
        }

        $rlKey = 'email-otp-verify:'.Cast::int($user->getAuthIdentifier());
        if (RateLimiter::tooManyAttempts($rlKey, 5)) {
            // Hard cap: burn the code so it can't be brute-forced — a fresh one is required.
            app(EmailOtp::class)->invalidate($user);
            Audit::record('auth.otp.locked', [
                'actor_id' => $user->getAuthIdentifier(),
                'actor_label' => $user->email,
                'meta' => ['factor' => 'email_otp'],
            ]);
            $this->addError('code', __('auth.otp_locked'));

            return null;
        }

        if (! app(EmailOtp::class)->verify($user, $this->code)) {
            RateLimiter::hit($rlKey, 600);
            $this->addError('code', __('auth.otp_invalid'));

            return null;
        }

        RateLimiter::clear($rlKey);
        session()->forget('2fa.user_id');
        Auth::login($user);
        session()->regenerate();
        Audit::record('auth.login.succeeded', [
            'actor_id' => $user->getAuthIdentifier(),
            'actor_label' => $user->email,
            'meta' => ['factor' => 'email_otp'],
        ]);

        // Back to the page that sent the visitor to sign in (a protected page, or an invitation
        // link) — the session's intended URL, set on this host only — else the dashboard.
        return $this->redirectIntended(route(AdminPlane::prefix().'.dashboard'), navigate: false);
    }

    public function resend(): void
    {
        $user = User::find(session('2fa.user_id'));

        // canResend = the cooldown gate; send() returns false when an hourly abuse cap is hit. Either
        // way the user sees the same "wait" message (no signal about which limit stopped them).
        if ($user instanceof User && app(EmailOtp::class)->canResend($user) && app(EmailOtp::class)->send($user)) {
            $this->sentStatus = __('auth.otp_resent');

            return;
        }

        $this->sentStatus = __('auth.otp_resend_wait');
    }

    public function render(): View
    {
        $view = view('admin::livewire.email-otp-challenge');
        $view->title(__('auth.otp_title'));

        return $view;
    }
}
