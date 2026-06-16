<?php

namespace App\Modules\Admin\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tenant admin login (session). Authenticates the global user against the peppered
 * Argon2id hasher; per-site authority is enforced downstream by EntryPolicy. Failed
 * attempts are rate-limited per email+IP to blunt brute-force / credential stuffing.
 * Tenant 2FA is optional (platform 2FA is mandatory — P0.2).
 */
#[Layout('admin::layouts.auth')]
final class Login extends Component
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public string $email = '';

    public string $password = '';

    public function login(): mixed
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $key = 'admin-login:'.sha1(Str::lower($this->email).'|'.(string) request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->addError('email', 'Quá nhiều lần thử. Vui lòng thử lại sau '.RateLimiter::availableIn($key).' giây.');

            return null;
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $this->addError('email', 'Thông tin đăng nhập không đúng.');

            return null;
        }

        RateLimiter::clear($key);

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
