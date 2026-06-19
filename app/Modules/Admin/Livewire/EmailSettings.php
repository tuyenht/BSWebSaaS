<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Tenant email-sender settings. Edits THIS site's override; when empty, mail falls back to
 * the platform default (hybrid — see TenantMailer). Two methods: SMTP password, or OAuth2
 * (Google/Microsoft/Yahoo) where the refresh token is obtained via the OAuth connect flow.
 *
 * Secrets are write-only: stored values are never sent to the browser — a "stored" flag is
 * shown and a blank field on save keeps the existing secret. All secrets persist AES-256-GCM
 * encrypted via SecretCipher.
 */
#[Layout('admin::layouts.app')]
final class EmailSettings extends Component
{
    public string $method = 'smtp_password';

    public string $smtpHost = '';

    public int $smtpPort = 587;

    public string $smtpEncryption = 'tls';

    public string $smtpUsername = '';

    public string $smtpPassword = '';

    public string $oauthProvider = 'google';

    public string $oauthClientId = '';

    public string $oauthClientSecret = '';

    public string $fromAddress = '';

    public string $fromName = '';

    public bool $smtpPasswordSet = false;

    public bool $oauthSecretSet = false;

    public ?string $oauthConnectedEmail = null;

    public bool $hasOverride = false;

    public bool $platformConfigured = false;

    public ?string $testResult = null;

    public function mount(): void
    {
        $cfg = app(SettingsRepository::class)->get('email');
        $this->hasOverride = ! empty($cfg);
        $this->platformConfigured = app(PlatformSettings::class)->get('email') !== null;

        if (empty($cfg)) {
            return;
        }

        $this->method = (string) ($cfg['method'] ?? 'smtp_password');
        $this->fromAddress = (string) ($cfg['from_address'] ?? '');
        $this->fromName = (string) ($cfg['from_name'] ?? '');

        $smtp = $cfg['smtp'] ?? [];
        $this->smtpHost = (string) ($smtp['host'] ?? '');
        $this->smtpPort = (int) ($smtp['port'] ?? 587);
        $this->smtpEncryption = (string) ($smtp['encryption'] ?? 'tls');
        $this->smtpUsername = (string) ($smtp['username'] ?? '');
        $this->smtpPasswordSet = ! empty($smtp['password']);

        $oauth = $cfg['oauth'] ?? [];
        $this->oauthProvider = (string) ($oauth['provider'] ?? 'google');
        $this->oauthClientId = (string) ($oauth['client_id'] ?? '');
        $this->oauthSecretSet = ! empty($oauth['client_secret']);
        $this->oauthConnectedEmail = is_string($oauth['email'] ?? null) && ! empty($oauth['refresh_token']) ? $oauth['email'] : null;
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $repo = app(SettingsRepository::class);
        $existing = $repo->get('email') ?? [];

        $cfg = [
            'method' => $this->method,
            'from_address' => $this->fromAddress ?: null,
            'from_name' => $this->fromName ?: null,
        ];

        if ($this->method === 'smtp_password') {
            $password = $this->smtpPassword !== ''
                ? SecretCipher::encrypt($this->smtpPassword)
                : ($existing['smtp']['password'] ?? null);

            $cfg['smtp'] = [
                'host' => $this->smtpHost,
                'port' => $this->smtpPort,
                'encryption' => $this->smtpEncryption,
                'username' => $this->smtpUsername,
                'password' => $password,
            ];
            // Keep any previously-connected oauth block so switching back doesn't re-auth.
            if (isset($existing['oauth'])) {
                $cfg['oauth'] = $existing['oauth'];
            }
        } else {
            $secret = $this->oauthClientSecret !== ''
                ? SecretCipher::encrypt($this->oauthClientSecret)
                : ($existing['oauth']['client_secret'] ?? null);

            $cfg['oauth'] = array_merge($existing['oauth'] ?? [], [
                'provider' => $this->oauthProvider,
                'client_id' => $this->oauthClientId,
                'client_secret' => $secret,
            ]);
            if (isset($existing['smtp'])) {
                $cfg['smtp'] = $existing['smtp'];
            }
        }

        $repo->put('email', 'config', $cfg);

        $this->reset('smtpPassword', 'oauthClientSecret');
        $this->mount();
        session()->flash('status', __('admin.saved'));
    }

    public function connect(): RedirectResponse|Redirector
    {
        $this->save();

        return redirect()->route('admin.oauth.start', ['provider' => $this->oauthProvider, 'purpose' => 'email']);
    }

    public function clearOverride(): void
    {
        app(SettingsRepository::class)->put('email', 'config', []);
        $this->reset();
        $this->mount();
        session()->flash('status', __('admin.override_cleared'));
    }

    public function sendTest(): void
    {
        $this->testResult = null;
        $user = auth()->user();
        $to = $user?->email;

        if (! $to) {
            return;
        }

        if (! app(TenantMailer::class)->apply()) {
            $this->testResult = 'error';

            return;
        }

        try {
            Mail::raw(__('admin.test_email_body'), function ($message) use ($to): void {
                $message->to($to)->subject(__('admin.test_email_subject'));
            });
            $this->testResult = 'ok';
        } catch (Throwable $e) {
            report($e);
            $this->testResult = 'error';
        }
    }

    /**
     * @return array<string, string>
     */
    private function rules(): array
    {
        if ($this->method === 'smtp_password') {
            return [
                'smtpHost' => 'required|string',
                'smtpPort' => 'required|integer|min:1|max:65535',
                'smtpUsername' => 'required|string',
                'fromAddress' => 'nullable|email',
            ];
        }

        return [
            'oauthProvider' => 'required|string|in:google,microsoft,yahoo',
            'oauthClientId' => 'required|string',
            'fromAddress' => 'nullable|email',
        ];
    }

    public function render(): View
    {
        return view('admin::livewire.email-settings')->title(__('admin.email_settings'));
    }
}
