<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Support\OAuthFlow;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Social-login OAuth app credentials per provider (Google, Facebook), stored per-tenant
 * (site_settings group 'oauth_social', key = provider). A provider's login button stays
 * disabled on the sign-in page until its client id + secret are saved and it's enabled.
 *
 * Client secrets are write-only (a "stored" flag is shown; blank-on-save keeps the value)
 * and persist AES-256-GCM encrypted via SecretCipher.
 */
#[Layout('admin::layouts.app')]
final class SocialSettings extends Component
{
    use TracksSavedState;

    /** @var list<string> The providers this screen may write; save() trusts it, so the browser cannot change it. */
    #[Locked]
    public array $providers = ['google', 'facebook'];

    /** @var array<string, array{client_id: string, client_secret: string, enabled: bool}> */
    public array $forms = [];

    /** @var array<string, bool> */
    #[Locked]
    public array $secretSet = [];

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the site-settings permission on every request (mount + every action).
        abort_unless(Gate::allows('settings.manage'), 403);
        // Never on someone's behalf: staff signed in for support cannot change this.
        app(SupportSession::class)->forbid();
    }

    public function mount(): void
    {
        $this->load();
    }

    /**
     * Save one provider's app, or (no provider: the save bar's "Lưu") every provider that differs
     * from what is saved. False when nothing was saved (nothing differed, or a provider this screen
     * does not offer was sent).
     */
    public function save(?string $provider = null): bool
    {
        $chosen = $provider === null ? $this->unsavedFields() : [$provider];
        if ($chosen === []) {
            return false;
        }
        $rules = [];
        foreach ($chosen as $key) {
            if (! in_array($key, $this->providers, true)) {
                return false;
            }
            $rules['forms.'.$key.'.client_id'] = ['nullable', 'string', 'max:255'];
            $rules['forms.'.$key.'.client_secret'] = ['nullable', 'string', 'max:1024'];
            $rules['forms.'.$key.'.enabled'] = ['boolean'];
        }
        $this->validate($rules);

        foreach ($chosen as $key) {
            $this->store($key);
        }
        $this->rememberSaved();
        session()->flash('status', __('admin.saved'));

        return true;
    }

    /** Back to the saved apps (the save bar's "Bỏ thay đổi"); a secret typed but not saved is dropped. */
    public function resetChanges(): void
    {
        $this->load();
        $this->resetErrorBag();
    }

    /** @return array<string, mixed> */
    protected function trackedState(): array
    {
        return $this->forms;
    }

    private function load(): void
    {
        $repo = app(SettingsRepository::class);

        foreach ($this->providers as $provider) {
            $cfg = $repo->get('oauth_social', $provider) ?? [];
            $this->forms[$provider] = [
                'client_id' => Cast::string($cfg['client_id'] ?? null),
                'client_secret' => '',
                'enabled' => (bool) ($cfg['enabled'] ?? false),
            ];
            $this->secretSet[$provider] = ! empty($cfg['client_secret']);
        }
        $this->rememberSaved();
    }

    private function store(string $provider): void
    {
        $form = $this->forms[$provider] ?? [];
        $repo = app(SettingsRepository::class);
        $existing = $repo->get('oauth_social', $provider) ?? [];

        $newSecret = (string) ($form['client_secret'] ?? '');
        $secret = $newSecret !== ''
            ? SecretCipher::encrypt($newSecret)
            : ($existing['client_secret'] ?? null);

        $repo->put('oauth_social', $provider, [
            'client_id' => (string) ($form['client_id'] ?? ''),
            'client_secret' => $secret,
            'enabled' => (bool) ($form['enabled'] ?? false),
        ]);

        $this->forms[$provider]['client_secret'] = '';
        $this->secretSet[$provider] = $secret !== null;
    }

    public function render(): View
    {
        $view = view('admin::livewire.social-settings', [
            'redirectUri' => OAuthFlow::callbackUrl(),
            'changes' => $this->unsavedCount(),
        ]);
        $view->title(__('admin.social_login'));

        return $view;
    }
}
