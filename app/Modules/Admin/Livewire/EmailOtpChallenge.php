<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\EmailOtp;
use App\Modules\Identity\Models\User;
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
    public string $code = '';

    public string $sentStatus = '';

    public function mount(): mixed
    {
        if (! session()->has('2fa.user_id')) {
            return $this->redirectRoute('admin.login', navigate: false);
        }

        return null;
    }

    public function verify(): mixed
    {
        $this->validate(['code' => 'required|string']);

        $user = User::find(session('2fa.user_id'));
        if (! $user instanceof User) {
            session()->forget('2fa.user_id');

            return $this->redirectRoute('admin.login', navigate: false);
        }

        $rlKey = 'email-otp-verify:'.$user->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($rlKey, 5)) {
            $this->addError('code', __('auth.too_many_attempts', ['seconds' => RateLimiter::availableIn($rlKey)]));

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

        return $this->redirectRoute('admin.dashboard', navigate: false);
    }

    public function resend(): void
    {
        $user = User::find(session('2fa.user_id'));

        if ($user instanceof User && app(EmailOtp::class)->canResend($user)) {
            app(EmailOtp::class)->send($user);
            $this->sentStatus = __('auth.otp_resent');

            return;
        }

        $this->sentStatus = __('auth.otp_resend_wait');
    }

    public function render(): View
    {
        return view('admin::livewire.email-otp-challenge')->title(__('auth.otp_title'));
    }
}
