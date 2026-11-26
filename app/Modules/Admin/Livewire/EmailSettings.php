<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
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
final class EmailSettings extends Component
{
    use TracksSavedState;

    /** The fields the save bar compares with the saved setup (a secret only while one is typed). */
    private const array TRACKED = ['provider', 'smtpHost', 'smtpPort', 'smtpEncryption', 'smtpUsername', 'smtpPassword',
        'oauthClientId', 'oauthClientSecret', 'fromAddress', 'fromName', 'replyTo'];

    /** The outboxes a website picks from, in the board's order; a mail provider signs in with OAuth. */
    public const array OUTBOXES = ['platform', 'google', 'microsoft', 'other'];

    private const array OAUTH_OUTBOXES = ['google', 'microsoft', 'yahoo'];

    /** Which sender this screen writes (this site's or the system's); set once, never by the browser. */
    #[Locked]
    public string $scope = 'site';

    #[Locked]
    public bool $isSuperAdmin = false;

    /** Which outbox a website sends through: the platform's shared one, a signed-in mailbox, or its own server. */
    public string $provider = 'platform';

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

    public string $replyTo = '';

    public bool $smtpPasswordSet = false;

    public bool $oauthSecretSet = false;

    public ?string $oauthConnectedEmail = null;

    /** The outbox last saved: Yahoo stays offered only to a website already on it. */
    #[Locked]
    public string $storedProvider = 'platform';

    /** The mailbox provider that sign-in belongs to (picking another one does not move it). */
    #[Locked]
    public ?string $oauthConnectedProvider = null;

    public bool $platformConfigured = false;

    public ?string $testResult = null;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so
        // re-check the baseline site-settings permission on every request.
        abort_unless(Gate::allows('settings.manage'), 403);
        // Never on someone's behalf: staff signed in for support cannot change this.
        app(SupportSession::class)->forbid();
    }

    public function mount(string $scope = 'site'): void
    {
        $this->loadConfig($scope);
        $this->rememberSaved();
    }

    /** Back to the saved setup (the save bar's "Bỏ thay đổi"); a secret typed but not saved is dropped. */
    public function resetChanges(): void
    {
        $this->reset(...self::TRACKED);
        $this->resetErrorBag();
        $this->testResult = null;
        $this->loadConfig($this->scope);
        $this->rememberSaved();
    }

    private function loadConfig(string $scope): void
    {
        $this->scope = $scope === 'platform' ? 'platform' : 'site';
        $this->isSuperAdmin = $this->checkSuperAdmin();

        // The system (platform) default is super_admin-only.
        if ($this->scope === 'platform') {
            abort_unless($this->isSuperAdmin, 403);
        }

        $cfg = $this->configRepo()->get('email');

        if ($this->scope === 'site') {
            // Whether the platform's outbox really sends (a blank password does not), as the mailer reads it.
            $this->platformConfigured = app(TenantMailer::class)->canSendWith(null);
        }

        if ($cfg === null || $cfg === []) {
            return;
        }

        $this->method = Cast::string($cfg['method'] ?? null, 'smtp_password');
        $this->provider = self::outboxOf($cfg);
        $this->storedProvider = $this->provider;
        $this->fromAddress = Cast::string($cfg['from_address'] ?? null);
        $this->fromName = Cast::string($cfg['from_name'] ?? null);
        $this->replyTo = Cast::string($cfg['reply_to'] ?? null);

        $smtp = Cast::arr($cfg['smtp'] ?? null);
        $this->smtpHost = Cast::string($smtp['host'] ?? null);
        $this->smtpPort = Cast::int($smtp['port'] ?? null, 587);
        $this->smtpEncryption = Cast::string($smtp['encryption'] ?? null, 'tls');
        $this->smtpUsername = Cast::string($smtp['username'] ?? null);
        $this->smtpPasswordSet = ! empty($smtp['password']);

        $oauth = Cast::arr($cfg['oauth'] ?? null);
        $this->oauthProvider = Cast::string($oauth['provider'] ?? null, 'google');
        $this->oauthClientId = Cast::string($oauth['client_id'] ?? null);
        $this->oauthSecretSet = ! empty($oauth['client_secret']);
        $connectedEmail = $oauth['email'] ?? null;
        $this->oauthConnectedEmail = is_string($connectedEmail) && ! empty($oauth['refresh_token']) ? $connectedEmail : null;
        $this->oauthConnectedProvider = $this->oauthConnectedEmail !== null ? $this->oauthProvider : null;
    }

    /**
     * The outbox a stored setup sends through. A setup saved before the choice existed is read by
     * what it holds: a signed-in mailbox, a mail server, or nothing of its own (the platform's).
     *
     * @param  array<string, mixed>  $cfg
     */
    public static function outboxOf(array $cfg): string
    {
        $stored = $cfg['provider'] ?? null;
        if (is_string($stored) && in_array($stored, [...self::OUTBOXES, 'yahoo'], true)) {
            return $stored;
        }

        if (($cfg['method'] ?? null) === 'oauth2') {
            $oauth = Cast::string(Cast::arr($cfg['oauth'] ?? null)['provider'] ?? null, 'google');

            return in_array($oauth, self::OAUTH_OUTBOXES, true) ? $oauth : 'google';
        }

        return Cast::string(Cast::arr($cfg['smtp'] ?? null)['host'] ?? null) !== '' ? 'other' : 'platform';
    }

    /** A pick shows its own setup at once: a mailbox signs in (OAuth), «Khác» is a mail server. */
    public function updatedProvider(): void
    {
        $this->syncMethod();
    }

    private function syncMethod(): void
    {
        if (in_array($this->provider, self::OAUTH_OUTBOXES, true)) {
            $this->method = 'oauth2';
            $this->oauthProvider = $this->provider;
        } elseif ($this->provider === 'other') {
            $this->method = 'smtp_password';
        }
    }

    /** True once written (a form that fails its checks stops at validation). */
    public function save(): bool
    {
        // Platform scope mutates global config → re-verify super_admin server-side (the
        // public $scope/$isSuperAdmin props are hydrated from the client, never trusted).
        if ($this->scope === 'platform') {
            abort_unless($this->checkSuperAdmin(), 403);
            $this->method = 'smtp_password';
        } else {
            $this->syncMethod();
        }

        $this->validate($this->rules(), ['smtpPort.in' => __('admin.smtp_port_mail_only', ['ports' => implode(', ', MailHostGuard::PORTS)])]);

        $repo = $this->configRepo();
        $existing = $repo->get('email') ?? [];

        $platformPick = $this->scope === 'site' && $this->provider === 'platform';

        $cfg = [
            'method' => $this->method,
            ...($this->scope === 'site' ? ['provider' => $this->provider] : []),
            'from_address' => $this->fromAddress ?: null,
            'from_name' => $this->fromName ?: null,
            'reply_to' => $this->replyTo ?: null,
        ];

        if ($platformPick) {
            // The shared outbox: what the website set up for its own stays stored, to pick again later.
            foreach (['smtp', 'oauth'] as $kept) {
                if (isset($existing[$kept])) {
                    $cfg[$kept] = $existing[$kept];
                }
            }
        } elseif ($this->method === 'smtp_password') {
            $existingSmtp = Cast::arr($existing['smtp'] ?? null);
            $password = $this->smtpPassword !== ''
                ? SecretCipher::encrypt($this->smtpPassword)
                : ($existingSmtp['password'] ?? null);

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
            $existingOauth = Cast::arr($existing['oauth'] ?? null);
            $secret = $this->oauthClientSecret !== ''
                ? SecretCipher::encrypt($this->oauthClientSecret)
                : ($existingOauth['client_secret'] ?? null);

            // A sign-in belongs to the mailbox it was made with: another provider's token never carries over.
            if (($existingOauth['provider'] ?? null) !== $this->oauthProvider) {
                unset($existingOauth['refresh_token'], $existingOauth['email']);
            }

            $cfg['oauth'] = array_merge($existingOauth, [
                'provider' => $this->oauthProvider,
                'client_id' => $this->oauthClientId,
                'client_secret' => $secret,
            ]);
            if (isset($existing['smtp'])) {
                $cfg['smtp'] = $existing['smtp'];
            }
        }

        $repo->put('email', 'config', $cfg);
        if ($this->scope !== 'platform') {
            app(TenantMailer::class)->forgetOwnOutboxFailing();
        }

        $this->reset('smtpPassword', 'oauthClientSecret');
        $this->loadConfig($this->scope);
        $this->rememberSaved();
        session()->flash('status', __('admin.saved'));

        return true;
    }

    public function connect(): RedirectResponse
    {
        // OAuth connect (3-legged) is tenant-scoped only for now.
        if ($this->scope === 'platform') {
            return redirect()->route('platform.settings.email');
        }

        $this->save();

        // Only a picked mailbox signs in, and with that mailbox (save() has checked the pick is offered).
        abort_unless(in_array($this->provider, self::OAUTH_OUTBOXES, true), 422);

        return redirect()->route('admin.oauth.start', ['provider' => $this->provider, 'purpose' => 'email']);
    }

    /** Forget the signed-in mailbox; mail goes through the platform's outbox until it is signed in again. */
    public function disconnect(): void
    {
        if ($this->scope === 'platform') {
            return;
        }

        $repo = app(SettingsRepository::class);
        $cfg = $repo->get('email') ?? [];
        $oauth = Cast::arr($cfg['oauth'] ?? null);
        unset($oauth['refresh_token'], $oauth['email']);
        $cfg['oauth'] = $oauth;
        $repo->put('email', 'config', $cfg);

        // Nothing the save bar tracks was written: edits not yet saved stay counted.
        $this->oauthConnectedEmail = null;
        $this->oauthConnectedProvider = null;
        session()->flash('status', __('admin.em_disconnected'));
    }

    public function sendTest(): void
    {
        $this->testResult = null;
        $to = auth()->user()?->email;

        if (! $to) {
            return;
        }

        if (! app(TenantMailer::class)->apply(fallback: false)) {
            $this->testResult = 'error';

            return;
        }

        try {
            Mail::raw(__('admin.test_email_body'), function (Message $message) use ($to): void {
                $message->to($to)->subject(__('admin.test_email_subject'));
            });
            $this->testResult = 'ok';
        } catch (Throwable $e) {
            report($e);
            $this->testResult = 'error';
        }
    }

    /** @return array<string, mixed> */
    protected function trackedState(): array
    {
        $state = [];
        foreach (self::TRACKED as $field) {
            $state[$field] = $this->{$field};
        }

        return $state;
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
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $outbox = ['provider' => ['required', 'string', Rule::in($this->yahooKept() ? [...self::OUTBOXES, 'yahoo'] : self::OUTBOXES)]];

        if ($this->scope === 'site' && $this->provider === 'platform') {
            return [...$outbox, 'fromAddress' => 'nullable|email', 'replyTo' => 'nullable|email'];
        }

        if ($this->scope !== 'site') {
            $outbox = [];
        }

        if ($this->method === 'smtp_password') {
            // The platform default must be a real, working sender (SMTP required). A tenant may leave
            // SMTP blank to send via the shared transport with just their own identity (name +
            // reply-to); SMTP is then all-or-nothing so a half-filled server can't be saved.
            $smtpRequired = $this->scope === 'platform';

            // A site's server must be on the public internet (the platform's is the operator's).
            $publicHost = function (string $attribute, mixed $value, Closure $fail): void {
                $refusal = $this->scope !== 'platform' && is_string($value) ? app(MailHostGuard::class)->refusal($value) : null;
                if ($refusal !== null) {
                    $fail(__($refusal === MailHostGuard::UNRESOLVABLE ? 'admin.smtp_host_unresolvable' : 'admin.smtp_host_private'));
                }
            };

            return [
                ...$outbox,
                'smtpHost' => [$smtpRequired ? 'required' : 'nullable', 'string', ...($smtpRequired ? [] : ['required_with:smtpUsername']), $publicHost],
                'smtpPort' => ['nullable', 'integer', ...($smtpRequired ? ['min:1', 'max:65535'] : [Rule::in(MailHostGuard::PORTS)])],
                'smtpUsername' => $smtpRequired ? 'required|string' : 'nullable|string|required_with:smtpHost',
                'fromAddress' => 'nullable|email',
                'replyTo' => 'nullable|email',
            ];
        }

        return [
            ...$outbox,
            'oauthProvider' => 'required|string|in:google,microsoft,yahoo',
            'oauthClientId' => 'required|string',
            'fromAddress' => 'nullable|email',
            'replyTo' => 'nullable|email',
        ];
    }

    /**
     * What the summary card says: whether the picked outbox sends, and where mail goes meanwhile
     * (the same order as TenantMailer: the website's own → the platform's → nowhere).
     *
     * @return array{ok: bool, outbox: string, fallback: bool}
     */
    private function deliveryStatus(): array
    {
        $ok = match ($this->provider) {
            'platform' => $this->platformConfigured,
            'other' => $this->smtpHost !== '' && $this->smtpUsername !== '' && $this->smtpPasswordSet,
            default => $this->oauthConnectedProvider === $this->provider,
        };
        // Set up but failing on its last send: mail is going out through the shared outbox meanwhile.
        if ($ok && $this->provider !== 'platform' && $this->provider === $this->storedProvider && app(TenantMailer::class)->ownOutboxFailing()) {
            $ok = false;
        }

        return ['ok' => $ok, 'outbox' => $this->provider, 'fallback' => ! $ok && $this->provider !== 'platform' && $this->platformConfigured];
    }

    /** A website already on Yahoo keeps that row; nobody new is offered it. */
    public function yahooKept(): bool
    {
        return $this->storedProvider === 'yahoo' || $this->oauthConnectedProvider === 'yahoo';
    }

    /** The address the platform's shared outbox sends from (what a customer sees in the From line). */
    private function platformFrom(): string
    {
        $from = Cast::string(Cast::arr(app(PlatformSettings::class)->get('email'))['from_address'] ?? null);

        return $from !== '' ? $from : Cast::string(config('mail.from.address'));
    }

    private function siteName(): string
    {
        $current = app(CurrentSite::class);

        return $current->hasId() ? Cast::string(Site::query()->whereKey($current->id())->value('name')) : '';
    }

    public function render(): View
    {
        $platform = $this->scope === 'platform';

        $view = view('admin::livewire.email-settings', [
            'changes' => $this->unsavedCount(),
            'status' => $platform ? null : $this->deliveryStatus(),
            'platformFrom' => $platform ? '' : $this->platformFrom(),
            'siteName' => $platform ? '' : $this->siteName(),
        ]);
        $view->layout($platform ? 'admin::layouts.platform' : 'admin::layouts.app');
        $view->title($platform ? __('admin.system_email') : __('admin.email_settings'));

        return $view;
    }
}
