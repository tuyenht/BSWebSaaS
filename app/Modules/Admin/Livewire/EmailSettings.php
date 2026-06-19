<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Email-sender settings, scoped:
 *  - scope=site (default): THIS tenant's override; empty → falls back to the platform
 *    default (hybrid, see TenantMailer). Gated by `settings.manage` (owner/admin).
 *  - scope=platform: the SYSTEM default every tenant inherits (platform_settings). Restricted
 *    to super_admin — the "highest admin" surface — and SMTP-password only (the platform
 *    default sender). Guarded server-side in mount() AND save() (never trusts the public prop).
 *
 * Secrets are write-only (a "stored" flag is shown; blank-on-save keeps the value) and
 * persist AES-256-GCM encrypted via SecretCipher.
 */
#[Layout('admin::layouts.app')]
final class EmailSettings extends Component
{
    public string $scope = 'site';

    public bool $isSuperAdmin = false;

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

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the baseline site-settings permission on every request.
        abort_unless(Gate::allows('settings.manage'), 403);
    }

    public function mount(string $scope = 'site'): void
    {
        $this->scope = $scope === 'platform' ? 'platform' : 'site';
        $this->isSuperAdmin = $this->checkSuperAdmin();

        // The system (platform) default is super_admin-only.
        if ($this->scope === 'platform') {
            abort_unless($this->isSuperAdmin, 403);
        }

        $cfg = $this->configRepo()->get('email');

        if ($this->scope === 'site') {
            $this->hasOverride = ! empty($cfg);
            $this->platformConfigured = app(PlatformSettings::class)->get('email') !== null;
        }

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
        // Platform scope mutates global config → re-verify super_admin server-side (the
        // public $scope/$isSuperAdmin props are hydrated from the client, never trusted).
        if ($this->scope === 'platform') {
            abort_unless($this->checkSuperAdmin(), 403);
            $this->method = 'smtp_password';
        }

        $this->validate($this->rules());

        $repo = $this->configRepo();
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
        $this->mount($this->scope);
        session()->flash('status', __('admin.saved'));
    }

    public function connect(): RedirectResponse|Redirector
    {
        // OAuth connect (3-legged) is tenant-scoped only for now.
        if ($this->scope === 'platform') {
            return redirect()->route('admin.settings.system.email');
        }

        $this->save();

        return redirect()->route('admin.oauth.start', ['provider' => $this->oauthProvider, 'purpose' => 'email']);
    }

    public function clearOverride(): void
    {
        // Only a tenant override can be cleared — never the system default.
        if ($this->scope === 'platform') {
            return;
        }

        app(SettingsRepository::class)->put('email', 'config', []);
        $this->reset();
        $this->mount();
        session()->flash('status', __('admin.override_cleared'));
    }

    public function sendTest(): void
    {
        $this->testResult = null;
        $to = auth()->user()?->email;

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

    private function checkSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user !== null && app(PlatformAccess::class)->isSuperAdmin($user);
    }

    private function configRepo(): SettingsRepository|PlatformSettings
    {
        return $this->scope === 'platform'
            ? app(PlatformSettings::class)
            : app(SettingsRepository::class);
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
        $platform = $this->scope === 'platform';

        return view('admin::livewire.email-settings')
            ->layout($platform ? 'admin::layouts.platform' : 'admin::layouts.app')
            ->title($platform ? __('admin.system_email') : __('admin.email_settings'));
    }
}
