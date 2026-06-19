<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin account settings. Section 1 = security (two-factor enrollment): generate a TOTP
 * secret + QR, confirm a code to enable, surface one-time recovery codes, and disable.
 * Reuses TwoFactorService (secret/QR/verify/recovery) so the login-time challenge becomes
 * reachable. Email / OAuth-app sections are added alongside.
 */
#[Layout('admin::layouts.app')]
final class Settings extends Component
{
    public ?string $tfaSecret = null;

    public ?string $tfaQr = null;

    public string $tfaCode = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public bool $showRecovery = false;

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
        if ($user instanceof User) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
        }

        $this->reset('showRecovery', 'recoveryCodes', 'tfaSecret', 'tfaQr', 'tfaCode');
    }

    public function render(): View
    {
        return view('admin::livewire.settings')->title(__('admin.settings'));
    }
}
