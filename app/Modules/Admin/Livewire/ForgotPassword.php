<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\TenantMailer;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tenant admin "forgot password" request. Validates the email and always replies with
 * the same generic confirmation to avoid account enumeration. Actual reset-link delivery
 * (mailer + token broker) is wired separately — this screen is the request entry point.
 */
#[Layout('admin::layouts.auth')]
final class ForgotPassword extends Component
{
    public string $email = '';

    public string $status = '';

    public function submit(): void
    {
        $this->validate(['email' => 'required|email']);

        // Configure the resolved mailer (tenant override → platform default) before send.
        app(TenantMailer::class)->apply();
        Password::sendResetLink(['email' => $this->email]);

        // Same response whether or not the address exists (no enumeration).
        $this->status = __('auth.reset_link_sent');
        $this->reset('email');
    }

    public function render(): View
    {
        return view('admin::livewire.forgot-password')->title(__('auth.forgot_title'));
    }
}
