<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use Illuminate\Support\Facades\Auth;
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

        $service = app(TwoFactorService::class);
        $code = trim($this->code);

        $ok = $this->useRecovery
            ? $service->consumeRecoveryCode($user, $code)
            : $service->verify((string) $user->two_factor_secret, (string) preg_replace('/\s+/', '', $code));

        if (! $ok) {
            $this->addError('code', __('auth.two_factor_invalid'));

            return null;
        }

        session()->forget('2fa.user_id');
        Auth::login($user);
        session()->regenerate();

        return $this->redirectRoute(AdminPlane::prefix().'.dashboard', navigate: false);
    }

    public function render(): View
    {
        $view = view('admin::livewire.two-factor-challenge');
        $view->title(__('auth.two_factor_title'));

        return $view;
    }
}
