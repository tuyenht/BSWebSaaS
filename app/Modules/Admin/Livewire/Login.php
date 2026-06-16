<?php

namespace App\Modules\Admin\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tenant admin login (session). Authenticates the global user against the peppered
 * Argon2id hasher; per-site authority is enforced downstream by EntryPolicy (W-B).
 * Tenant 2FA is optional (platform 2FA is mandatory — P0.2).
 */
#[Layout('admin::layouts.auth')]
final class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(): mixed
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            $this->addError('email', 'Thông tin đăng nhập không đúng.');

            return null;
        }

        // Prevent session fixation. The Session facade resolves the store from the
        // container, so it works both under the web stack and in Livewire unit tests.
        Session::regenerate();

        return $this->redirectRoute('admin.dashboard', navigate: false);
    }

    public function render(): View
    {
        return view('admin::livewire.login');
    }
}
