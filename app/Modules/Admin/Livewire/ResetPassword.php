<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Support\Cast;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
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
            'password' => 'required|string|min:8|confirmed',
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
