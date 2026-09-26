<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\SwitchesLocale;
use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step two of email+password login: the TOTP (or recovery-code) challenge. The pending
 * user id is stashed in the session by Login; the user is only authenticated here once a
 * valid code is verified by TwoFactorService.
 */
#[Layout('admin::layouts.auth')]
final class TwoFactorChallenge extends Component
{
    use SwitchesLocale;

    public string $code = '';

    public bool $useRecovery = false;

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

        if (! $user instanceof User || $user->two_factor_confirmed_at === null) {
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

        // Bound TOTP/recovery guesses the same way the email OTP challenge is (parity): 5 wrong
        // codes lock the challenge for 10 minutes, so a stolen password can't be paired with an
        // online brute-force of the 6-digit code.
        $rlKey = 'admin-2fa-verify:'.Cast::int($user->getAuthIdentifier());
        if (RateLimiter::tooManyAttempts($rlKey, 5)) {
            Audit::record('auth.2fa.locked', [
                'actor_id' => $user->getAuthIdentifier(),
                'actor_label' => $user->email,
                'meta' => ['factor' => $this->useRecovery ? 'recovery' : 'totp'],
            ]);
            $this->addError('code', __('auth.too_many_attempts', ['seconds' => RateLimiter::availableIn($rlKey)]));

            return null;
        }

        $service = app(TwoFactorService::class);
        $code = trim($this->code);

        $ok = $this->useRecovery
            ? $service->consumeRecoveryCode($user, $code)
            : $service->verify((string) $user->two_factor_secret, (string) preg_replace('/\s+/', '', $code));

        if (! $ok) {
            RateLimiter::hit($rlKey, 600);
            $this->addError('code', __('auth.two_factor_invalid'));

            return null;
        }

        RateLimiter::clear($rlKey);
        session()->forget('2fa.user_id');
        Auth::login($user);
        session()->regenerate();
        Audit::record('auth.login.succeeded', [
            'actor_id' => $user->getAuthIdentifier(),
            'actor_label' => $user->email,
            'meta' => ['factor' => $this->useRecovery ? 'recovery' : 'totp'],
        ]);

        // Back to the page that sent the visitor to sign in (a protected page, or an invitation
        // link) — the session's intended URL, set on this host only — else the dashboard.
        return $this->redirectIntended(route(AdminPlane::prefix().'.dashboard'), navigate: false);
    }

    public function render(): View
    {
        $view = view('admin::livewire.two-factor-challenge');
        $view->title(__('auth.two_factor_title'));

        return $view;
    }
}
