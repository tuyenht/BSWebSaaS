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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        if (! $this->mayTryHere()) {
            // A link is checked with one hash of its token (the password hasher, Argon2id), and only
            // when the account has one waiting. Every other way out does the same work on a random
            // value, so the reply time tells neither a member from anyone else, nor an account from
            // an unknown address.
            Hash::make(Str::random(64));
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

    /**
     * A known account with a link waiting, which may enter this website (the platform console serves
     * every account; a website only its members). Anything else is refused before the broker, which
     * would answer an unknown address or a missing link without the hash.
     */
    private function mayTryHere(): bool
    {
        $user = Password::broker()->getUser(['email' => $this->email]);
        if (! $user instanceof User) {
            return false;
        }

        if (app(CurrentSite::class)->hasId() && ! app(EnsureTenantMember::class)->admits($user)) {
            return false;
        }

        $broker = Cast::string(config('auth.defaults.passwords'));

        return DB::table(Cast::string(config("auth.passwords.{$broker}.table")))
            ->where('email', $user->getEmailForPasswordReset())
            ->exists();
    }

    public function render(): View
    {
        $view = view('admin::livewire.reset-password');
        $view->title(__('auth.reset_title'));

        return $view;
    }
}
