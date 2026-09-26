<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\SwitchesLocale;
use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tenant admin password reset (token from the emailed link). Uses Laravel's password
 * broker against the global identity; the new password is hashed by the configured
 * PepperedArgon2Id hasher via the model's 'hashed' cast.
 */
#[Layout('admin::layouts.auth')]
final class ResetPassword extends Component
{
    use SwitchesLocale;

    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->token = Cast::string(request('token'));
        $this->email = Cast::string(request('email'));
    }

    public function resetPassword(): mixed
    {
        $this->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            [
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function (CanResetPassword $user, string $password): void {
                if ($user instanceof User) {
                    $user->forceFill(['password' => $password])->save();

                    // Security-sensitive event — parity with login/OTP/2FA, which all audit.
                    Audit::record('auth.password.reset', [
                        'actor_id' => $user->getAuthIdentifier(),
                        'actor_label' => $user->email,
                    ]);
                }
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            session()->flash('status', __('auth.reset_done'));

            return $this->redirectRoute(AdminPlane::prefix().'.login', navigate: false);
        }

        $this->addError('email', __('auth.reset_failed'));

        return null;
    }

    public function render(): View
    {
        $view = view('admin::livewire.reset-password');
        $view->title(__('auth.reset_title'));

        return $view;
    }
}
