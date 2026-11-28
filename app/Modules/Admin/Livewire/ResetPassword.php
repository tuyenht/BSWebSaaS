<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\FocusesAuthField;
use App\Modules\Admin\Livewire\Concerns\SwitchesLocale;
use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Http\Middleware\EnsureTenantMember;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tenant admin password reset (token from the emailed link). Uses Laravel's password
 * broker against the global identity; the new password is hashed by the configured
 * PepperedArgon2Id hasher via the model's 'hashed' cast.
 *
 * On a website's host only someone who may enter that website's admin (EnsureTenantMember) can
 * use a link here; anyone else gets the same «link no longer valid» line as a bad token, and the
 * token is left untouched. The platform console (no website) serves every account.
 */
#[Layout('admin::layouts.auth')]
final class ResetPassword extends Component
{
    use FocusesAuthField;
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
        // The email shows as "Account" on this page too: name it the same way in its error message.
        $this->validateOrFocus([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ], ['email' => Str::lower(__('auth.email_label'))]);

        if (! $this->mayResetHere()) {
            $this->addError('email', __('auth.reset_failed'));

            return null;
        }

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

    private function mayResetHere(): bool
    {
        if (! app(CurrentSite::class)->hasId()) {
            return true;
        }

        $user = Password::broker()->getUser(['email' => $this->email]);

        return ! $user instanceof User || app(EnsureTenantMember::class)->admits($user);
    }

    public function render(): View
    {
        $view = view('admin::livewire.reset-password');
        $view->title(__('auth.reset_title'));

        return $view;
    }
}
