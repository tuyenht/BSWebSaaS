<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\OAuthFlow;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
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
    /** @var list<string> */
    public array $providers = ['google', 'facebook'];

    /** @var array<string, array{client_id: string, client_secret: string, enabled: bool}> */
    public array $forms = [];

    /** @var array<string, bool> */
    public array $secretSet = [];

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the site-settings permission on every request (mount + every action).
        abort_unless(Gate::allows('settings.manage'), 403);
    }

    public function mount(): void
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
    }

    public function save(string $provider): void
    {
        if (! in_array($provider, $this->providers, true)) {
            return;
        }

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
        session()->flash('status', __('admin.saved'));
    }

    public function render(): View
    {
        $view = view('admin::livewire.social-settings', [
            'redirectUri' => OAuthFlow::callbackUrl(),
        ]);
        $view->title(__('admin.social_login'));

        return $view;
    }
}
