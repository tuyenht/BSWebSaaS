<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\LoginTurnstile;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * System-wide login security — one control today: whether the Cloudflare Turnstile challenge is
 * enforced on the login form (every tenant admin + the platform console). Platform-only, super_admin
 * surface ("the highest admin"); the toggle lets an operator turn an already-configured challenge
 * off/on without a redeploy (e.g. if a Cloudflare hostname mis-config is locking people out). The CF
 * keys still gate rendering — this only flips the enforce switch. Guarded server-side in boot() AND
 * save() (the livewire/update endpoint bypasses route middleware; never trust the client-hydrated prop).
 */
#[Layout('admin::layouts.platform')]
final class LoginSecuritySettings extends Component
{
    public bool $turnstileLogin = true;

    public function boot(): void
    {
        abort_unless($this->isSuperAdmin(), 403);
    }

    public function mount(): void
    {
        $this->turnstileLogin = LoginTurnstile::enabled();
    }

    public function save(): void
    {
        abort_unless($this->isSuperAdmin(), 403);

        $security = app(PlatformSettings::class)->get(LoginTurnstile::GROUP) ?? [];
        $security[LoginTurnstile::KEY] = $this->turnstileLogin;
        app(PlatformSettings::class)->put(LoginTurnstile::GROUP, 'config', $security);

        session()->flash('status', __('admin.saved'));
    }

    public function render(): View
    {
        $view = view('admin::livewire.login-security-settings');
        $view->title(__('admin.settings_login_security'));

        return $view;
    }

    private function isSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user !== null && app(PlatformAccess::class)->isSuperAdmin($user);
    }
}
