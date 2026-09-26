<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Notifications\TwoFactorDisabledNotification;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The signed-in user's own account page: profile (display name), password change
 * (current-password gated), two-factor enrollment (TOTP secret + QR, confirm to enable,
 * one-time recovery codes, disable) and social connects. Everything here scopes to
 * auth()->user() only — site-wide settings live in their own tabs.
 */
#[Layout('admin::layouts.app')]
final class Settings extends Component
{
    public string $profileName = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $tfaSecret = null;

    public ?string $tfaQr = null;

    public string $tfaCode = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public bool $showRecovery = false;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so re-assert
        // an authenticated session on every request (each action also scopes to auth()->user()).
        abort_unless(auth()->check(), 403);
    }

    public function mount(): void
    {
        $user = auth()->user();
        $this->profileName = $user instanceof User ? $user->name : '';
    }

    /** Update the user's own display name (the topbar short-name derives from it). */
    public function saveProfile(): void
    {
        $this->validate(['profileName' => ['required', 'string', 'max:120']]);

        $user = auth()->user();
        if ($user instanceof User) {
            $user->forceFill(['name' => trim($this->profileName)])->save();
            session()->flash('profile_saved', __('admin.saved'));
        }
    }

    /** Change the user's own password — gated by their CURRENT password, never by role. */
    public function changePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = auth()->user();
        if ($user instanceof User) {
            $user->forceFill(['password' => Hash::make($this->password)])->save();

            // Rotating the hash invalidates every OTHER session via AuthenticateSession (they
            // still carry the old hash). Re-seed THIS session's stored hash so the person making
            // the change is not logged out on their next page load — this action runs on the
            // livewire/update endpoint, which does not pass through AuthenticateSession.
            session()->put(
                'password_hash_'.Cast::string(config('auth.defaults.guard')),
                $user->getAuthPassword(),
            );

            $this->reset('current_password', 'password', 'password_confirmation');
            session()->flash('password_changed', __('admin.password_changed'));
        }
    }

    public function startTwoFactor(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $service = app(TwoFactorService::class);
        $this->tfaSecret = $service->generateSecret();
        $this->tfaQr = $service->qrSvg($user, $this->tfaSecret);
        $this->showRecovery = false;
    }

    public function confirmTwoFactor(): void
    {
        $this->validate(['tfaCode' => 'required|string', 'tfaSecret' => 'required|string']);

        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $ok = app(TwoFactorService::class)->enable(
            $user,
            (string) $this->tfaSecret,
            (string) preg_replace('/\s+/', '', $this->tfaCode),
        );

        if (! $ok) {
            $this->addError('tfaCode', __('auth.two_factor_invalid'));

            return;
        }

        $this->recoveryCodes = $user->refresh()->two_factor_recovery_codes ?? [];
        $this->showRecovery = true;
        $this->reset('tfaSecret', 'tfaQr', 'tfaCode');
    }

    public function disableTwoFactor(): void
    {
        $user = auth()->user();
        $wasOn = false;
        if ($user instanceof User) {
            $wasOn = $user->two_factor_confirmed_at !== null;

            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
        }

        if ($user instanceof User && $wasOn) {
            // Weakening sign-in is a security event: on record, and the account owner is told in
            // the bell and by mail (locked notice) — a hijacked session cannot do it quietly.
            Audit::record('auth.2fa.disabled', [
                'actor_id' => $user->getKey(),
                'actor_label' => $user->email,
            ]);
            $user->notify((new TwoFactorDisabledNotification(
                app(SiteTime::class)->now()->format('d/m/Y H:i'),
            ))->forCurrentSite());
        }

        $this->reset('showRecovery', 'recoveryCodes', 'tfaSecret', 'tfaQr', 'tfaCode');
    }

    public function disconnect(string $provider): void
    {
        $user = auth()->user();
        if ($user instanceof User) {
            $user->socialIdentities()->where('provider', $provider)->delete();
        }
    }

    public function render(): View
    {
        $user = auth()->user();

        // Providers that support social connect, as [key => label].
        $providers = [];
        foreach (Cast::arr(config('oauth.providers')) as $key => $cfg) {
            $cfg = Cast::arr($cfg);
            if (in_array('connect', Cast::strings($cfg['supports'] ?? null), true)) {
                $providers[Cast::string($key)] = Cast::string($cfg['label'] ?? null, Cast::string($key));
            }
        }

        $connected = $user instanceof User
            ? $user->socialIdentities()->pluck('email', 'provider')
            : collect();

        $view = view('admin::livewire.settings', [
            'socialProviders' => $providers,
            'connected' => $connected,
        ]);
        $view->title(__('admin.settings'));

        return $view;
    }
}
