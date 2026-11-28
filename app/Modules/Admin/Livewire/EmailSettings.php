<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\ShowsSettingsToast;
use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Models\MailDaily;
use App\Modules\Admin\Support\Mail\MailQuotaGate;
use App\Modules\Admin\Support\Mail\MailSendContext;
use App\Modules\Admin\Support\MailBrand;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\MailNotifyTo;
use App\Modules\Admin\Support\MailRelay;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Forms\Support\FormDraft;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\Site;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
 *
 * A website's page has two tabs: «Gửi thư» (the outbox above) and «Chữ ký & thương hiệu»: the logo,
 * the button colour and the signature every mail it sends carries (MailBrand). They share the one
 * save bar; a save writes only the part that changed, so a signature is saved without the outbox
 * being checked again, and the other way round.
 */
final class EmailSettings extends Component
{
    use ShowsSettingsToast;
    use TracksSavedState;
    use WithMediaLibraryPicker {
        openImagePicker as private openLibrary;
    }

    /** The fields the save bar compares with the saved setup (a secret only while one is typed). */
    private const array TRACKED = ['provider', 'relay', 'relayRegion', 'sendDomain', 'smtpHost', 'smtpPort', 'smtpEncryption', 'smtpUsername', 'smtpPassword',
        'oauthClientId', 'oauthClientSecret', 'fromAddress', 'fromLocal', 'fromName', 'replyTo', 'notifyTo'];

    /** The fields of «Chọn dịch vụ»: changing one makes its last check stale («Chưa kiểm tra»). */
    private const array RELAY_FIELDS = ['relay', 'relayRegion', 'sendDomain', 'smtpHost', 'smtpPort', 'smtpEncryption', 'smtpUsername', 'smtpPassword'];

    /** The fields of «Chữ ký & thương hiệu» (the signature is compared as it would be stored). */
    private const array BRAND_TRACKED = ['brandColor', 'logoKey', 'sigLayout', 'sigHtml'];

    /** Test sends a member may start per minute on one website («Kiểm tra & lưu kết nối» and «Gửi thử» together). */
    private const int TEST_SENDS_PER_MINUTE = 5;

    /** Test mails one website may send through its own outbox in one of its days, to any address. */
    public const int TEST_SENDS_PER_DAY = 20;

    /** The tabs of a website's page, in the board's order («Mẫu thư» comes with the mail templates). */
    public const array TABS = ['send', 'sign'];

    /** The mailbox providers whose address cannot carry a website's own «info@…» (no suggestion from them). */
    private const array PUBLIC_MAILBOXES = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.com.vn', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com'];

    /** The parts before @ «Gửi thông báo tới» suggests on the website's domain, in the board's order. */
    private const array NOTIFY_PREFIXES = ['info', 'contact'];

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

    /** A website's «Địa chỉ gửi đi» on «Nhà cung cấp khác»: the part before @ (the part after is its sending domain). */
    public string $fromLocal = '';

    /**
     * «Gửi thông báo tới» (MailNotifyTo): changed only through addNotify()/removeNotify(), so every
     * address is checked as it is added; one not well-formed stays shown (in red) until removed.
     *
     * @var list<string>
     */
    #[Locked]
    public array $notifyTo = [];

    /** The website's own domain («+ info@…» suggestions when no sending domain is known yet); '' = none. */
    #[Locked]
    public string $siteDomain = '';

    public bool $smtpPasswordSet = false;

    /** The mail service «Nhà cung cấp khác» sends through (MailRelay::KEYS); the board starts on SendGrid. */
    public string $relay = 'sendgrid';

    /** Mailgun's (us|eu) or Amazon SES's region, from MailRelay::regions(). */
    public string $relayRegion = '';

    /** The domain a mail service sends from. */
    public string $sendDomain = '';

    /** The relay last saved ('' = none): a stored key belongs to it and never carries to another. */
    #[Locked]
    public string $storedRelay = '';

    /** Whether the stored «Nhà cung cấp khác» setup has all a send needs. */
    #[Locked]
    public bool $ownReady = false;

    /** «Đã kết nối và gửi thử thành công»: the stored setup passed «Kiểm tra & lưu kết nối» and nothing changed since. */
    #[Locked]
    public bool $relayChecked = false;

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

    /**
     * «Gửi thư kiểm tra tới»: the one address a test mail goes to ('' = the member's own). Typed in
     * the browser, so sendTest() checks it again on every send before anything leaves.
     */
    public string $testTo = '';

    /** The line under «Gửi thư kiểm tra tới» after a test mail left (who to, and when). */
    #[Locked]
    public string $testSentNote = '';

    /** The tab shown (?tab=sign opens on it); the browser switches at once and tells the server with its next request. */
    public string $tab = 'send';

    /** The mail's button colour: one of the swatches, '' = the website's own brand colour. */
    public string $brandColor = '';

    /** The logo at the top of every mail: a picture of the library by its key ('' = none). */
    public string $logoKey = '';

    /** Where the signature puts the logo: MailBrand::LAYOUTS. */
    public string $sigLayout = 'left';

    /** The signature as the editor writes it ('' = the layout's own, filled from «Thông tin chung»). */
    public string $sigHtml = '';

    /** The website's own brand colour (its theme's), what '' above stands for. */
    #[Locked]
    public string $siteColor = MailBrand::FALLBACK_COLOR;

    /** What the library was opened for: the logo, or a picture in the signature (set on the server only). */
    #[Locked]
    public string $pickerFor = '';

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
        $tab = request()->query('tab');
        $this->tab = is_string($tab) && in_array($tab, self::TABS, true) ? $tab : 'send';
        $this->loadConfig($scope);
        $this->rememberSaved();
        // the board fills the field with the member's own address
        $this->testTo = $this->scope === 'site' ? Cast::string(auth()->user()?->email) : '';
    }

    /** Back to the saved setup (the save bar's "Bỏ thay đổi"); a secret typed but not saved is dropped. */
    public function resetChanges(): void
    {
        $this->reset(...self::TRACKED, ...self::BRAND_TRACKED);
        $this->resetErrorBag();
        $this->testResult = null;
        $this->testSentNote = '';
        $this->loadConfig($this->scope);
        $this->rememberSaved();
        $this->sendSignatureToEditor();
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
            $this->loadBrand();
            $this->notifyTo = app(MailNotifyTo::class)->emails();
            $this->siteDomain = $this->ownDomain();
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
        $this->fromLocal = $this->scope === 'site' && str_contains($this->fromAddress, '@') ? Str::beforeLast($this->fromAddress, '@') : '';

        $smtp = Cast::arr($cfg['smtp'] ?? null);
        $this->smtpHost = Cast::string($smtp['host'] ?? null);
        $this->smtpPort = Cast::int($smtp['port'] ?? null, 587);
        $this->smtpEncryption = Cast::string($smtp['encryption'] ?? null, 'tls');
        $this->smtpUsername = Cast::string($smtp['username'] ?? null);
        $this->smtpPasswordSet = ! empty($smtp['password']);

        if ($this->scope === 'site') {
            // A setup saved before the services existed is its own mail server («Email khác»).
            $stored = MailRelay::stored($cfg);
            $this->relay = $stored ?? ($this->provider === 'other' || $this->smtpHost !== '' ? 'smtp' : 'sendgrid');
            $this->storedRelay = $stored ?? ($this->smtpHost !== '' || $this->smtpPasswordSet ? 'smtp' : '');
            $region = Cast::string($cfg['relay_region'] ?? null);
            $this->relayRegion = in_array($region, MailRelay::regions($this->relay), true) ? $region : MailRelay::defaultRegion($this->relay);
            $this->sendDomain = Cast::string($cfg['send_domain'] ?? null);
            $this->ownReady = MailRelay::ready($cfg);
            // a passed check stops counting while the mailer marks this outbox failing (a send could not set it up)
            $this->relayChecked = $this->ownReady && isset($cfg['checked_at']) && ! app(TenantMailer::class)->ownOutboxFailing();
        }

        $oauth = Cast::arr($cfg['oauth'] ?? null);
        $this->oauthProvider = Cast::string($oauth['provider'] ?? null, 'google');
        $this->oauthClientId = Cast::string($oauth['client_id'] ?? null);
        $this->oauthSecretSet = ! empty($oauth['client_secret']);
        $connectedEmail = $oauth['email'] ?? null;
        $this->oauthConnectedEmail = is_string($connectedEmail) && ! empty($oauth['refresh_token']) ? $connectedEmail : null;
        $this->oauthConnectedProvider = $this->oauthConnectedEmail !== null ? $this->oauthProvider : null;
    }

    /** «Chữ ký & thương hiệu» as saved; a logo whose picture left the library shows empty. */
    private function loadBrand(): void
    {
        $brand = app(MailBrand::class);
        $stored = $brand->stored();
        $this->siteColor = $brand->siteColor();
        $this->brandColor = $stored['color'] !== null && $stored['color'] !== $this->siteColor ? $stored['color'] : '';
        $this->logoKey = $stored['logo_media_id'] !== null
            ? Cast::string(Media::query()->whereKey($stored['logo_media_id'])->where('mime', 'like', 'image/%')->value('storage_key'))
            : '';
        $this->sigLayout = $stored['layout'];
        $this->sigHtml = $stored['html'] ?? '';
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

    /** A tile of «Chọn dịch vụ»: its own fields; a key or login typed for another service never carries over. */
    public function pickRelay(string $relay): void
    {
        if ($this->scope !== 'site' || ! in_array($relay, MailRelay::KEYS, true) || $relay === $this->relay) {
            return;
        }
        $this->relay = $relay;
        $this->smtpPassword = '';
        $this->relayChecked = false;
        $this->resetErrorBag(self::RELAY_FIELDS);
        if ($relay === $this->storedRelay) {
            // back to the saved service: its saved fields again
            $cfg = Cast::arr($this->configRepo()->get('email'));
            $smtp = Cast::arr($cfg['smtp'] ?? null);
            $this->smtpHost = Cast::string($smtp['host'] ?? null);
            $this->smtpPort = Cast::int($smtp['port'] ?? null, 587);
            $this->smtpEncryption = Cast::string($smtp['encryption'] ?? null, 'tls');
            $this->smtpUsername = Cast::string($smtp['username'] ?? null);
            $region = Cast::string($cfg['relay_region'] ?? null);
            $this->relayRegion = in_array($region, MailRelay::regions($relay), true) ? $region : MailRelay::defaultRegion($relay);

            return;
        }
        $this->smtpHost = '';
        $this->smtpPort = 587;
        $this->smtpEncryption = 'tls';
        $this->smtpUsername = '';
        $this->relayRegion = MailRelay::defaultRegion($relay);
    }

    /** Any change in «Chọn dịch vụ» makes its last check stale. */
    public function updated(string $name): void
    {
        if (in_array($name, self::RELAY_FIELDS, true)) {
            $this->relayChecked = false;
        }
    }

    /**
     * «Email gửi đi» of «Email khác»: a Gmail or Yahoo address fills in its provider's server, only
     * while no server is typed.
     */
    public function updatedSmtpUsername(): void
    {
        if ($this->scope !== 'site' || $this->relay !== 'smtp' || trim($this->smtpHost) !== '') {
            return;
        }
        $server = MailRelay::mailboxServer($this->smtpUsername);
        if ($server !== null) {
            $this->smtpHost = $server['host'];
            $this->smtpPort = $server['port'];
            $this->smtpEncryption = $server['encryption'];
        }
    }

    /**
     * «Kiểm tra & lưu kết nối»: saves, then sends a test mail to the member who pressed it through
     * the service just saved; success is remembered until a field of the service changes.
     */
    public function checkRelay(): void
    {
        if ($this->scope !== 'site' || $this->provider !== 'other') {
            return;
        }
        // refused before saving: the member waits, then saves and checks in one go
        if ($this->testSendThrottled()) {
            return;
        }
        // and while the website's test mails of the day are used up: nothing could be checked
        if ($this->testDayFull()) {
            $full = $this->testDayFullMessage();
            $this->addError('relay', $full);
            $this->notify($full, 'error');

            return;
        }
        $this->relayChecked = false;
        try {
            $saved = $this->save();
        } catch (ValidationException $e) {
            $this->notify(__('admin.em_rl_invalid_toast', ['count' => count($e->errors())]), 'error');

            throw $e;
        }
        if (! $saved) {
            return;
        }

        // the check mails the member who pressed it, whatever «Gửi thư kiểm tra tới» holds
        $refused = $this->deliverTest(Cast::string(auth()->user()?->email), 'relay');
        $repo = $this->configRepo();
        $cfg = Cast::arr($repo->get('email'));
        if ($refused) {
            // nothing was tested: an earlier check stands (reached when the day's count fills
            // between the check before saving and this send)
            $this->relayChecked = $this->ownReady && isset($cfg['checked_at']);

            return;
        }
        unset($cfg['checked_at']);
        if ($this->testResult === 'ok') {
            $cfg['checked_at'] = now()->utc()->toIso8601String();
        }
        $repo->put('email', 'config', $cfg);
        $this->relayChecked = $this->testResult === 'ok';
        if (! $this->relayChecked) {
            return;
        }
        $this->notify(__('admin.em_rl_ok_toast'));
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

        // Only what changed is checked and written: a signature or the notice addresses save
        // without the outbox, and back.
        $changed = $this->unsavedFields();
        $brandChanged = $this->scope === 'site' && array_intersect($changed, self::BRAND_TRACKED) !== [];
        $notifyChanged = $this->scope === 'site' && in_array('notifyTo', $changed, true);
        $senderChanged = array_diff($changed, [...self::BRAND_TRACKED, 'notifyTo']) !== [] || (! $brandChanged && ! $notifyChanged);

        try {
            $this->validate(
                [
                    ...($senderChanged ? $this->senderRules() : []),
                    ...($brandChanged ? $this->brandRules() : []),
                    ...($notifyChanged ? ['notifyTo' => [$this->notifyRule(...)]] : []),
                ],
                [
                    'replyTo.email' => __('admin.em_reply_invalid'),
                    'fromLocal.regex' => __('admin.em_from_local_invalid'),
                    'fromLocal.max' => __('admin.em_from_local_invalid'),
                    'smtpPort.in' => __('admin.smtp_port_mail_only', ['ports' => implode(', ', MailHostGuard::PORTS)]),
                    'smtpPassword.required' => __($this->relay === 'smtp' ? 'admin.em_rl_err_pass' : 'admin.em_rl_err_key'),
                    'sendDomain.required' => __('admin.em_rl_err_domain'),
                    'sendDomain.regex' => __('admin.em_rl_err_domain'),
                    'sendDomain.max' => __('admin.em_rl_err_domain'),
                    'relayRegion.required' => __('admin.em_rl_err_region'),
                    'relayRegion.in' => __('admin.em_rl_err_region'),
                    'smtpUsername.required' => __('admin.em_rl_err_login'),
                    'relay.required' => __('admin.em_rl_err_relay'),
                    'relay.in' => __('admin.em_rl_err_relay'),
                    'sigHtml.max' => __('admin.em_sig_too_long'),
                    'brandColor.in' => __('admin.em_brand_color_invalid'),
                    'sigLayout.in' => __('admin.em_sig_layout_invalid'),
                ],
            );
        } catch (ValidationException $e) {
            // The save bar names the first error; the tab that holds it opens.
            $this->showTabOf(array_keys($e->errors()));

            throw $e;
        }

        if ($brandChanged && ! $this->saveBrand()) {
            $this->showTabOf(['logoKey']);

            return false;
        }
        if ($notifyChanged) {
            app(MailNotifyTo::class)->put($this->notifyTo);
        }
        if (! $senderChanged) {
            $this->loadBrand();
            $this->notifyTo = app(MailNotifyTo::class)->emails();
            $this->rememberSaved();
            if ($brandChanged) {
                $this->sendSignatureToEditor();
            }
            session()->flash('status', __('admin.saved'));

            return true;
        }

        $repo = $this->configRepo();
        $existing = $repo->get('email') ?? [];

        $platformPick = $this->scope === 'site' && $this->provider === 'platform';
        // What «Chọn dịch vụ» last saved, kept while another outbox is picked; a passed check only while nothing of it changed.
        $relayKept = array_intersect_key($existing, array_flip(['relay', 'relay_region', 'send_domain',
            ...(array_intersect($changed, self::RELAY_FIELDS) === [] ? ['checked_at'] : [])]));

        $cfg = [
            'method' => $this->method,
            ...($this->scope === 'site' ? ['provider' => $this->provider] : []),
            'from_address' => $this->scope === 'site' ? $this->siteFromAddress($existing) : ($this->fromAddress ?: null),
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
            $cfg += $relayKept;
        } elseif ($this->method === 'smtp_password') {
            $existingSmtp = Cast::arr($existing['smtp'] ?? null);
            // A key belongs to the service it was saved for (validation asks for a new one on a switch).
            $relay = $this->scope === 'site' ? $this->relay : 'smtp';
            $password = $this->smtpPassword !== ''
                ? SecretCipher::encrypt($this->smtpPassword)
                : ($this->scope === 'platform' || $this->keySaved() ? ($existingSmtp['password'] ?? null) : null);

            // A service's server is the relay table's, whatever the browser sent; «Email khác» takes the typed one.
            $server = $relay === 'smtp' ? null : MailRelay::server($relay, $this->relayRegion);
            $cfg['smtp'] = [
                'host' => $server['host'] ?? $this->smtpHost,
                'port' => $server['port'] ?? $this->smtpPort,
                'encryption' => $server['encryption'] ?? $this->smtpEncryption,
                'username' => $relay === 'smtp' || MailRelay::hasOwnLogin($relay) ? $this->smtpUsername : MailRelay::fixedLogin($relay),
                'password' => $password,
            ];
            if ($this->scope === 'site') {
                $cfg['relay'] = $relay;
                $cfg['relay_region'] = MailRelay::regions($relay) !== [] ? $this->relayRegion : null;
                $cfg['send_domain'] = $relay === 'smtp' ? null : strtolower(trim($this->sendDomain));
                if (isset($relayKept['checked_at'])) {
                    $cfg['checked_at'] = $relayKept['checked_at'];
                }
            }
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
            $cfg += $relayKept;
        }

        $repo->put('email', 'config', $cfg);
        if ($this->scope !== 'platform') {
            app(TenantMailer::class)->forgetOwnOutboxFailing();
        }

        $this->reset('smtpPassword', 'oauthClientSecret');
        $this->loadConfig($this->scope);
        $this->dispatch('em-key-saved');
        $this->rememberSaved();
        if ($brandChanged) {
            $this->sendSignatureToEditor();
        }
        session()->flash('status', __('admin.saved'));

        return true;
    }

    /**
     * Opens the tab that holds the first of these fields.
     *
     * @param  list<string>  $fields
     */
    private function showTabOf(array $fields): void
    {
        if ($this->scope !== 'site' || $fields === []) {
            return;
        }
        $tab = in_array($fields[0], self::BRAND_TRACKED, true) ? 'sign' : 'send';
        if ($tab !== $this->tab) {
            $this->tab = $tab;
            $this->dispatch('email-tab', tab: $tab);
        }
    }

    /** Writes «Chữ ký & thương hiệu»; false (with the error under the logo) when its picture left the library. */
    private function saveBrand(): bool
    {
        // Only the fields that changed are written over what is stored: a colour saved from an older
        // page leaves a newer logo or signature be.
        $changed = array_flip($this->unsavedFields());
        $logoId = null;
        if (isset($changed['logoKey']) && $this->logoKey !== '') {
            $logoId = Media::query()->where('storage_key', $this->logoKey)->where('mime', 'like', 'image/%')->value('id');
            if (! is_int($logoId)) {
                $this->addError('logoKey', __('admin.em_logo_gone'));

                return false;
            }
        }

        $repo = app(SettingsRepository::class);
        $stored = app(MailBrand::class)->stored();
        if (isset($changed['logoKey']) || isset($changed['brandColor'])) {
            $repo->put('email', 'brand', [
                'logo_media_id' => isset($changed['logoKey']) ? $logoId : $stored['logo_media_id'],
                'color' => isset($changed['brandColor']) ? ($this->brandColor !== '' ? $this->brandColor : null) : $stored['color'],
            ]);
        }
        if (isset($changed['sigLayout']) || isset($changed['sigHtml'])) {
            $layout = isset($changed['sigLayout']) ? $this->sigLayout : $stored['layout'];
            $repo->put('email', 'signature', [
                'layout' => $layout,
                'html' => isset($changed['sigHtml']) ? app(MailBrand::class)->clean($this->sigHtml, $layout) : $stored['html'],
            ]);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function brandRules(): array
    {
        return [
            'brandColor' => ['nullable', 'string', Rule::in($this->swatchColors())],
            'logoKey' => ['nullable', 'string', 'max:512'],
            'sigLayout' => ['required', 'string', Rule::in(MailBrand::LAYOUTS)],
            'sigHtml' => ['nullable', 'string', 'max:'.MailBrand::SIGNATURE_MAX],
        ];
    }

    /**
     * The button colours offered: the board's eight, after the website's own when it is none of them.
     *
     * @return list<string>
     */
    private function swatchColors(): array
    {
        $colors = array_keys(MailBrand::SWATCHES);

        return in_array($this->siteColor, $colors, true) ? $colors : [$this->siteColor, ...$colors];
    }

    /** A swatch: the mail's buttons and headings take that colour (the website's own is stored as following it). */
    public function pickBrandColor(string $color): void
    {
        if ($this->scope !== 'site') {
            return;
        }
        $color = MailBrand::hex($color) ?? '';
        if (! in_array($color, $this->swatchColors(), true)) {
            $this->addError('brandColor', __('admin.em_brand_color_invalid'));

            return;
        }
        $this->resetErrorBag('brandColor');
        $this->brandColor = $color === $this->siteColor ? '' : $color;
    }

    /**
     * A layout button: where the logo sits, and the signature that layout shows (what was written for
     * it earlier on this page, or '' for its own).
     */
    public function setSigLayout(string $layout, string $html = ''): void
    {
        if ($this->scope !== 'site' || ! in_array($layout, MailBrand::LAYOUTS, true)) {
            return;
        }
        // Taken even when too long, so the server holds what the editor shows; «Lưu» then refuses it.
        $this->sigLayout = $layout;
        $this->sigHtml = $html;
        $this->updatedSigHtml();
    }

    /** «Về mặc định»: the layout's own signature again (saved with «Lưu», undone with «Bỏ thay đổi»). */
    public function resetSignature(): void
    {
        if ($this->scope !== 'site') {
            return;
        }
        $this->sigHtml = '';
        $this->resetErrorBag('sigHtml');
        $this->sendSignatureToEditor(saved: false);
        $this->notify(__('admin.em_sig_reset_done'));
    }

    public function updatedTab(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'send';
        }
    }

    public function updatedSigHtml(): void
    {
        $this->resetErrorBag('sigHtml');
        if (mb_strlen($this->sigHtml) > MailBrand::SIGNATURE_MAX) {
            $this->addError('sigHtml', __('admin.em_sig_too_long'));
        }
    }

    /** The logo box: the library opens on pictures, for the logo. */
    public function pickLogo(): void
    {
        if ($this->scope !== 'site') {
            return;
        }
        $this->pickerFor = 'logo';
        $this->openLibrary('single');
    }

    /** The signature's «Ảnh»: the library opens on pictures, for one put at the caret. */
    public function pickSignatureImage(): void
    {
        if ($this->scope !== 'site') {
            return;
        }
        $this->pickerFor = 'sig';
        $this->openLibrary('single');
    }

    /** The picker is opened only through pickLogo() / pickSignatureImage(). */
    public function openImagePicker(string $mode = 'single'): void
    {
        if ($this->pickerFor !== '') {
            $this->openLibrary('single');
        }
    }

    /** «Gỡ logo»: mail goes out with the website's name instead; the picture stays in the library. */
    public function removeLogo(): void
    {
        $this->logoKey = '';
        $this->resetErrorBag('logoKey');
    }

    protected function pickedSingleImage(Media $media): void
    {
        if (! str_starts_with($media->mime, 'image/')) {
            return;
        }
        if ($this->pickerFor === 'logo') {
            $this->logoKey = $media->storage_key;
            $this->resetErrorBag('logoKey');
        } elseif ($this->pickerFor === 'sig') {
            // At most 200px across in the signature, never wider than the picture itself.
            $width = is_int($media->width) && $media->width > 0 ? min(200, $media->width) : 200;
            $this->dispatch('email-signature-image', url: app(MediaUrlResolver::class)->url($media->storage_key, 600),
                alt: Cast::string($media->alt[app()->getLocale()] ?? null), width: $width);
        }
        $this->pickerFor = '';
    }

    /** The editor keeps its own page part: after a load, a save or «Về mặc định» it is handed the signature again. */
    private function sendSignatureToEditor(bool $saved = true): void
    {
        if ($this->scope === 'site') {
            $this->dispatch('email-signature-load', layout: $this->sigLayout, html: $this->sigHtml, saved: $saved);
        }
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

    /**
     * «Gửi thư kiểm tra»: a test mail to the address typed in (empty = the member's own; the system's
     * page always mails the member). One address only, and through the shared outbox only to a
     * verified email of a member of this website.
     */
    public function sendTest(): void
    {
        $this->testResult = null;
        $this->testSentNote = '';
        $this->resetErrorBag('testTo');
        $own = Cast::string(auth()->user()?->email);
        if ($own === '') {
            return;
        }
        $typed = $this->scope === 'site' ? trim($this->testTo) : '';
        $to = $typed === '' ? $own : $typed;

        $problem = $this->testAddressProblem($to);
        if ($problem !== null) {
            $this->addError('testTo', $problem);
            $this->js("document.getElementById('em-test-to')?.focus()");

            return;
        }

        $this->deliverTest($to, 'testTo');
    }

    /** Why a typed address cannot take a test mail (null = it can): one well-formed address, nothing else. */
    private function testAddressProblem(string $to): ?string
    {
        // a list (or a line break with another header after it) is never one address
        if (mb_check_encoding($to, 'UTF-8') && preg_match('/[,;\s]/u', $to) === 1 && substr_count($to, '@') > 1) {
            return Cast::string(__('admin.em_test_one'));
        }

        return mb_check_encoding($to, 'UTF-8') && FormDraft::isEmail($to) ? null : Cast::string(__('admin.em_test_bad'));
    }

    /**
     * Sends the test mail to $to through the outbox the website picked (never another one), with the
     * checks of that outbox; a refusal is shown under $field. Not a notification, so like before it is
     * neither logged in «Thư gần đây» nor counted on the shared outbox's day (RecordTenantMail).
     * True when one of those checks refused it (nothing was tried).
     */
    private function deliverTest(string $to, string $field): bool
    {
        $this->testResult = null;
        $this->testSentNote = '';

        if ($to === '' || $this->testSendThrottled()) {
            return true;
        }
        // each test opens a connection to a server the member typed in or picked: a few a minute at most
        RateLimiter::hit($this->testLimitKey(), 60);

        if (! app(TenantMailer::class)->apply(fallback: false)) {
            $this->testResult = 'error';

            return false;
        }

        if ($this->scope === 'site') {
            $refusal = app(MailSendContext::class)->via === 'own'
                ? ($this->testDayUsedUp() ? $this->testDayFullMessage() : null)
                : ($this->memberMayReceive($to) ? null : Cast::string(__('admin.em_test_platform_only')));
            if ($refusal !== null) {
                $this->addError($field, $refusal);
                if ($field === 'relay') {
                    $this->notify($refusal, 'error');
                }

                return true;
            }
        }

        try {
            // Through the shared mail frame, so the test shows the website's logo, colour and signature.
            $mail = (new MailMessage)->subject(__('admin.test_email_subject'))->line(__('admin.test_email_body'));
            $views = [
                'html' => new HtmlString((string) $mail->render()),
                'text' => new HtmlString((string) app(Markdown::class)->renderText('notifications::email', $mail->toArray())),
            ];
            Mail::send($views, [], function (Message $message) use ($to): void {
                $message->to($to)->subject(__('admin.test_email_subject'));
            });
            $this->testResult = 'ok';
            $this->testSentNote = Cast::string(__('admin.em_test_sent_to', ['to' => $to, 'time' => app(SiteTime::class)->now()->format('H:i')]));
        } catch (Throwable $e) {
            report($e);
            $this->testResult = 'error';
        }

        return false;
    }

    /**
     * Takes one of the website's test mails of its day through its own outbox (its own time zone);
     * true when the day's ones are used up. Counted on attempt: each one opens a connection.
     */
    private function testDayUsedUp(): bool
    {
        $now = app(SiteTime::class)->now();
        $untilTomorrow = (int) ceil(abs($now->diffInSeconds($now->addDay()->startOfDay())));

        return RateLimiter::hit($this->testDayKey($now), $untilTomorrow + 3600) > self::TEST_SENDS_PER_DAY;
    }

    /** Whether the website's test mails of its day are used up already (reads the count, takes none). */
    private function testDayFull(): bool
    {
        return RateLimiter::attempts($this->testDayKey(app(SiteTime::class)->now())) >= self::TEST_SENDS_PER_DAY;
    }

    /** The website's count of test mails for the day of $now (its own time zone); the same instant names the key and its expiry. */
    private function testDayKey(CarbonInterface $now): string
    {
        return 'mail-test-day:'.app(CurrentSite::class)->id().':'.$now->toDateString();
    }

    private function testDayFullMessage(): string
    {
        return Cast::string(__('admin.em_test_day_full', ['count' => self::TEST_SENDS_PER_DAY]));
    }

    /**
     * Whether the shared outbox may carry a test mail to $to: the member's own address (as before), or
     * a verified email of a member of this website, so the platform never mails a stranger in its name.
     */
    private function memberMayReceive(string $to): bool
    {
        $current = app(CurrentSite::class);
        // ASCII case only: a Unicode look-alike (K → k) is no match for the member's own address
        if (strtolower($to) === strtolower(Cast::string(auth()->user()?->email))) {
            return true;
        }
        if (! $current->hasId()) {
            return false;
        }
        $siteId = $current->id();

        // users.email is citext: the address matches whatever its case
        return User::query()->where('email', $to)->whereNotNull('email_verified_at')
            ->whereExists(fn (QueryBuilder $m): QueryBuilder => $m->selectRaw('1')
                ->from('model_has_roles')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', User::class)
                ->where('model_has_roles.site_id', $siteId))
            ->exists();
    }

    /** One count per member and website, shared by «Kiểm tra & lưu kết nối» and «Gửi thử». */
    private function testLimitKey(): string
    {
        $current = app(CurrentSite::class);

        return 'mail-test-send:'.($this->scope === 'site' && $current->hasId() ? $current->id() : 'platform').':'.Cast::int(auth()->id());
    }

    /** True (with a message that says how long to wait) once this member used up this minute's test sends. */
    private function testSendThrottled(): bool
    {
        $key = $this->testLimitKey();
        if (! RateLimiter::tooManyAttempts($key, self::TEST_SENDS_PER_MINUTE)) {
            return false;
        }
        $message = __('admin.em_test_wait', ['seconds' => max(1, RateLimiter::availableIn($key))]);
        $this->testResult = null;
        if ($this->scope === 'site' && $this->provider === 'other') {
            // under the service tiles, where «Kiểm tra & lưu kết nối» was pressed
            $this->addError('relay', $message);
        }
        $this->notify($message, 'error');

        return true;
    }

    /** @return array<string, mixed> */
    protected function trackedState(): array
    {
        $state = [];
        foreach (self::TRACKED as $field) {
            $state[$field] = $this->{$field};
        }
        if ($this->scope === 'site') {
            $state['brandColor'] = $this->brandColor;
            $state['logoKey'] = $this->logoKey;
            $state['sigLayout'] = $this->sigLayout;
            $state['sigHtml'] = mb_strlen($this->sigHtml) > MailBrand::SIGNATURE_MAX
                ? $this->sigHtml
                : (app(MailBrand::class)->clean($this->sigHtml, $this->sigLayout) ?? '');
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

        if ($this->method === 'smtp_password' && $this->scope === 'site' && $this->relay !== 'smtp') {
            return [...$outbox, ...$this->relayRules(), 'fromAddress' => 'nullable|email', 'replyTo' => 'nullable|email'];
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
                ...($this->scope === 'site' ? [
                    'relay' => ['required', 'string', Rule::in(MailRelay::KEYS)],
                    // a server needs its password: the stored one, or one pasted now; a switch from a
                    // service with a saved key asks for one too, so that key never vanishes unnoticed
                    'smtpPassword' => [Rule::requiredIf(fn (): bool => ! $this->keySaved() && (trim($this->smtpHost) !== '' || $this->smtpPasswordSet))],
                ] : []),
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
     * The checks of «Tên và địa chỉ người gửi»: a website types only the part before @ of its
     * address, and only on «Nhà cung cấp khác» (the platform's outbox sends from the platform's
     * address, a signed-in mailbox from itself).
     *
     * @return array<string, mixed>
     */
    private function senderRules(): array
    {
        $rules = $this->rules();
        if ($this->scope !== 'site') {
            return $rules;
        }
        unset($rules['fromAddress']);
        // an address kept as saved is not checked again: the old rule let `sales+web@…` through
        if ($this->provider === 'other' && $this->loginMailbox() === '' && $this->fromLocalEdited()) {
            $rules['fromLocal'] = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || $value === '' || ! $this->fromLocalEdited()) {
                        return;
                    }
                    $domain = $this->fromDomain();
                    if ($domain === '') {
                        // a part typed with no domain to put after @ would be dropped on save
                        $fail($this->noDomainMessage());
                    } elseif (filter_var($value.'@'.$domain, FILTER_VALIDATE_EMAIL) === false) {
                        $fail(__('admin.em_from_local_invalid'));
                    }
                }];
        }

        return $rules;
    }

    /**
     * The address a website's save stores. On «Nhà cung cấp khác»: none when «Email khác» signs in
     * to a public mailbox (it sends only as that login, MailRelay::defaultFrom); the stored one as
     * it is while its part before @ is not edited (even on another domain than the one it sends
     * from now: a save of the name or the reply address never rewrites it); once edited, the part
     * typed and the sending domain (none typed = the service's own default). On any other outbox
     * the stored one stays as it was (that outbox does not send from it).
     *
     * @param  array<string, mixed>  $existing
     */
    private function siteFromAddress(array $existing): ?string
    {
        $kept = $existing['from_address'] ?? null;
        $kept = is_string($kept) && $kept !== '' ? $kept : null;
        if ($this->provider !== 'other') {
            return $kept;
        }
        if ($this->loginMailbox() !== '') {
            return null;
        }
        if (! $this->fromLocalEdited()) {
            return $kept;
        }
        $domain = $this->fromDomain();
        $local = trim($this->fromLocal);

        return $local !== '' && $domain !== '' ? $local.'@'.$domain : null;
    }

    /**
     * The domain «Nhà cung cấp khác» sends from: a service's sending domain, or the domain of
     * «Email gửi đi» of «Email khác» (a server saved with a login that is no address: the domain
     * of the address it sends from); '' while none is known.
     */
    private function fromDomain(): string
    {
        if ($this->relay === 'smtp') {
            $login = trim($this->smtpUsername);
            $from = str_contains($login, '@') ? $login : trim($this->fromAddress);

            return str_contains($from, '@') ? mb_strtolower(Str::afterLast($from, '@')) : '';
        }
        $domain = mb_strtolower(trim($this->sendDomain));

        return preg_match('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/', $domain) === 1 ? $domain : '';
    }

    /**
     * The login of «Email khác» when it is an address of a public mailbox (Gmail, Outlook, Yahoo…):
     * such a mailbox sends only as itself, so the sending address is that login, never typed; ''
     * otherwise.
     */
    private function loginMailbox(): string
    {
        if ($this->provider !== 'other' || $this->relay !== 'smtp') {
            return '';
        }
        $login = trim($this->smtpUsername);

        return str_contains($login, '@') && in_array(mb_strtolower(Str::afterLast($login, '@')), self::PUBLIC_MAILBOXES, true) ? $login : '';
    }

    /** Whether the part before @ of «Địa chỉ gửi đi» differs from the one saved (only then is the address rebuilt). */
    private function fromLocalEdited(): bool
    {
        return in_array('fromLocal', $this->unsavedFields(), true);
    }

    /** What to do while «Nhà cung cấp khác» has no domain to send from yet (the preview and the error say it). */
    private function noDomainMessage(): string
    {
        return Cast::string(__($this->relay === 'smtp' ? 'admin.em_from_no_domain_smtp' : 'admin.em_from_no_domain'));
    }

    /** The website's own domain (a custom one, without `www.`), '' when it runs only on a platform subdomain. */
    private function ownDomain(): string
    {
        $current = app(CurrentSite::class);
        if (! $current->hasId()) {
            return '';
        }
        // the address its pages are served on, when that is its own domain (not a platform subdomain)
        $host = mb_strtolower((string) preg_replace('/^www\./i', '', (string) app(SiteResolver::class)->primaryHostFor($current->id())));
        foreach (Cast::strings(config('tenancy.base_domains')) as $base) {
            if ($host === mb_strtolower($base) || str_ends_with($host, '.'.mb_strtolower($base))) {
                return '';
            }
        }

        return $host;
    }

    /**
     * «Gửi thông báo tới»: what was typed (or pasted, several at once) is added, each address
     * once; one not well-formed is kept and named in the error. A pasted «Tên <địa chỉ>» gives its
     * address (the name, quoted or not, is dropped); the rest splits at commas, semicolons and
     * line breaks, and at spaces only between bare addresses.
     */
    public function addNotify(string $typed): void
    {
        if ($this->scope !== 'site') {
            return;
        }
        $text = (string) preg_replace('/(^|[,;\r\n>]|[^\s,;<>"]*@[^\s,;<>"]*\s)\s*(?:"[^"]*"\s*)?[^<>@,;\r\n]*<([^<>]*)>/u', '$1,$2,', mb_substr($typed, 0, 2000));
        $parts = preg_split('/[\s,;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $have = array_map(mb_strtolower(...), $this->notifyTo);
        foreach ($parts as $part) {
            $part = trim($part, " \t<>\"'");
            if ($part === '' || in_array(mb_strtolower($part), $have, true)) {
                continue;
            }
            if (count($this->notifyTo) >= MailNotifyTo::MAX) {
                $this->checkNotify(full: true);

                return;
            }
            $this->notifyTo[] = mb_substr($part, 0, 254);
            $have[] = mb_strtolower($part);
        }
        $this->checkNotify();
    }

    /**
     * The × on an address of «Gửi thông báo tới» (and Backspace in the empty box): takes that
     * address off, case aside. Named by the address, not its place, so a second tap or a held key
     * sent before the list came back never takes off another one.
     */
    public function removeNotify(string $email): void
    {
        if ($this->scope !== 'site') {
            return;
        }
        $folded = mb_strtolower(trim(mb_substr($email, 0, 254)));
        foreach ($this->notifyTo as $i => $have) {
            if (mb_strtolower($have) === $folded) {
                array_splice($this->notifyTo, $i, 1);
                $this->checkNotify();

                return;
            }
        }
    }

    /** The error under «Gửi thông báo tới» as the list is now (none once every address is well-formed). */
    private function checkNotify(bool $full = false): void
    {
        $this->resetErrorBag('notifyTo');
        $message = $this->notifyMessage() ?? ($full ? __('admin.em_notify_max', ['max' => MailNotifyTo::MAX]) : null);
        if ($message !== null) {
            $this->addError('notifyTo', $message);
        }
    }

    /** Names the addresses of «Gửi thông báo tới» that are not well-formed; null when there are none. */
    private function notifyMessage(): ?string
    {
        $bad = array_values(array_filter($this->notifyTo, fn (string $email): bool => ! FormDraft::isEmail($email)));
        if ($bad !== []) {
            return Cast::string(__('admin.em_notify_invalid', ['list' => implode(', ', $bad)]));
        }

        return count($this->notifyTo) > MailNotifyTo::MAX ? Cast::string(__('admin.em_notify_max', ['max' => MailNotifyTo::MAX])) : null;
    }

    private function notifyRule(string $attribute, mixed $value, Closure $fail): void
    {
        $message = $this->notifyMessage();
        if ($message !== null) {
            $fail($message);
        }
    }

    /**
     * What «Tên và địa chỉ người gửi» and «Gửi thông báo tới» draw: the sender line as a customer
     * will see it (the name typed, else the website's; the address the picked outbox really sends
     * from, '' while it has none yet, with `empty` saying why), whether the address is typed or
     * locked (a signed-in mailbox, or «Email khác» on a public mailbox), the domain after @ (the
     * stored address's own while its part before @ is not edited, `keptNote` then naming the
     * domain an edit moves it to) and the suggestions.
     *
     * @return array{name: string, address: string, empty: string, edit: bool, locked: string, lockedHelp: string, domain: string, suffix: string, keptNote: string, defaultLocal: string, suggestions: list<string>, placeholderDomain: string}
     */
    private function senderView(string $siteName, string $platformFrom): array
    {
        $mailbox = in_array($this->provider, self::OAUTH_OUTBOXES, true);
        $linked = $mailbox && $this->oauthConnectedProvider === $this->provider ? Cast::string($this->oauthConnectedEmail) : '';
        $loginLock = $this->loginMailbox();
        $edit = $this->provider === 'other' && $loginLock === '';
        $domain = $this->provider === 'other' ? $this->fromDomain() : '';
        // what an empty «Địa chỉ gửi đi» sends from (MailRelay::defaultFrom): the login of «Email khác»,
        // no-reply@ on a service
        $login = trim($this->smtpUsername);
        $smtp = $this->relay === 'smtp';
        $defaultLocal = $smtp ? (str_contains($login, '@') ? Str::beforeLast($login, '@') : '') : 'no-reply';
        $typed = trim($this->fromLocal);
        // the stored address is what sends until its part before @ is edited (siteFromAddress)
        $stored = trim($this->fromAddress);
        $kept = $edit && str_contains($stored, '@') && ! $this->fromLocalEdited() ? $stored : '';
        $keptDomain = $kept !== '' ? mb_strtolower(Str::afterLast($kept, '@')) : '';
        $address = match (true) {
            $this->provider === 'platform' => $platformFrom,
            $mailbox => $linked,
            $loginLock !== '' => $loginLock,
            $kept !== '' => $kept,
            $domain === '' => '',
            $typed !== '' => $typed.'@'.$domain,
            $smtp => str_contains($login, '@') ? $login : '',
            default => 'no-reply@'.$domain,
        };
        $name = trim($this->fromName) !== '' ? trim($this->fromName) : $siteName;

        $linkedDomain = $linked !== '' ? mb_strtolower(Str::afterLast($linked, '@')) : '';
        $suggestOn = match (true) {
            $domain !== '' && ! in_array($domain, self::PUBLIC_MAILBOXES, true) => $domain,
            $linkedDomain !== '' && ! in_array($linkedDomain, self::PUBLIC_MAILBOXES, true) => $linkedDomain,
            default => $this->siteDomain,
        };
        $have = array_map(mb_strtolower(...), $this->notifyTo);
        $suggestions = [];
        if ($suggestOn !== '' && count($this->notifyTo) < MailNotifyTo::MAX) {
            foreach (self::NOTIFY_PREFIXES as $prefix) {
                if (! in_array($prefix.'@'.$suggestOn, $have, true)) {
                    $suggestions[] = $prefix.'@'.$suggestOn;
                }
            }
        }

        return [
            'name' => $name !== '' ? $name : Cast::string(__('admin.em_sender_site_fallback')),
            'address' => $address,
            'empty' => $edit && $domain === '' ? $this->noDomainMessage() : Cast::string(__('admin.em_sender_no_addr')),
            'edit' => $edit,
            'locked' => $mailbox ? $linked : $loginLock,
            'lockedHelp' => Cast::string(__($mailbox ? 'admin.em_from_help_locked' : 'admin.em_from_help_login')),
            'domain' => $domain,
            'suffix' => $keptDomain !== '' ? $keptDomain : $domain,
            'keptNote' => $keptDomain !== '' && $domain !== '' && $keptDomain !== $domain ? Cast::string(__('admin.em_from_kept', ['domain' => $domain])) : '',
            'defaultLocal' => $defaultLocal,
            'suggestions' => $suggestions,
            'placeholderDomain' => $suggestOn !== '' ? $suggestOn : 'congty.vn',
        ];
    }

    /**
     * The checks of a mail service: its key (a stored one only for the same service), its sending
     * domain, its region and login where it has them; its server from the relay table must be public too.
     *
     * @return array<string, mixed>
     */
    private function relayRules(): array
    {
        $relay = $this->relay;
        $regions = MailRelay::regions($relay);

        return [
            'relay' => ['required', 'string', Rule::in(MailRelay::KEYS), function (string $attribute, mixed $value, Closure $fail) use ($relay): void {
                $server = MailRelay::server($relay, $this->relayRegion);
                $refusal = $server !== null ? app(MailHostGuard::class)->refusal($server['host']) : null;
                // The table's own name not resolving right now is a passing DNS fault: the send path
                // takes it the same way (TenantMailer); a private address still stops the save.
                if ($refusal !== null && $refusal !== MailHostGuard::UNRESOLVABLE) {
                    $fail(__('admin.smtp_host_private'));
                }
            }],
            'smtpPassword' => [Rule::requiredIf(fn (): bool => ! $this->keySaved()), 'nullable', 'string', 'max:1024'],
            'sendDomain' => ['required', 'string', 'max:253', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/i'],
            ...($regions !== [] ? ['relayRegion' => ['required', 'string', Rule::in($regions)]] : []),
            ...(MailRelay::hasOwnLogin($relay) ? ['smtpUsername' => ['required', 'string', 'max:255']] : []),
        ];
    }

    /** Whether the stored key (or password) belongs to the service picked now, so a blank field keeps it. */
    private function keySaved(): bool
    {
        return $this->smtpPasswordSet && $this->relay === $this->storedRelay;
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
            'other' => $this->ownReady,
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

    /**
     * What «Chữ ký & thương hiệu» draws: the swatches with their names and contrast, the logo, the
     * layouts' own signatures, the variables with this website's values and its social links.
     *
     * @return array<string, mixed>
     */
    private function signTab(): array
    {
        $brand = app(MailBrand::class);
        $color = $this->brandColor !== '' ? $this->brandColor : $this->siteColor;
        $swatches = [];
        foreach ($this->swatchColors() as $hex) {
            $key = MailBrand::SWATCHES[$hex] ?? null;
            $name = $key !== null ? __('admin.em_sw_'.$key) : __('admin.em_sw_site');
            $ok = MailBrand::contrast($hex) >= MailBrand::AA;
            $swatches[] = [
                'hex' => $hex,
                'on' => $hex === $color,
                'ink' => MailBrand::textOn($hex),
                'label' => __($ok ? 'admin.em_sw_note_ok' : 'admin.em_sw_note_low', [
                    'name' => $name,
                    'ink' => __(MailBrand::textOn($hex) === '#ffffff' ? 'admin.em_ink_white' : 'admin.em_ink_black'),
                ]),
            ];
        }
        $logo = $this->logoKey !== '' ? app(MediaUrlResolver::class)->url($this->logoKey, 400) : null;
        $values = $brand->values();
        $links = app(SiteIdentity::class)->for(app()->getLocale())['social_links'] ?? null;
        $socials = [];
        foreach (is_array($links) ? $links : [] as $network => $url) {
            if (is_string($url) && $url !== '') {
                $key = 'admin.gs.f.'.$network;
                $socials[] = ['name' => Lang::has($key) ? Cast::string(__($key)) : Str::headline((string) $network), 'url' => $url];
            }
        }
        $defaults = [];
        foreach (MailBrand::LAYOUTS as $layout) {
            $defaults[$layout] = MailBrand::defaultSignature($layout);
        }

        return [
            'color' => $color,
            'lowContrast' => MailBrand::contrast($color) < MailBrand::AA,
            'swatches' => $swatches,
            'logo' => $logo,
            'defaults' => $defaults,
            'values' => $values,
            'socials' => $socials,
            'canPick' => Gate::allows('media.view'),
        ];
    }

    private function siteName(): string
    {
        $current = app(CurrentSite::class);

        return $current->hasId() ? Cast::string(Site::query()->whereKey($current->id())->value('name')) : '';
    }

    /**
     * Today's mails left on the shared outbox (the board's «Còn N thư hôm nay» and its bar): the
     * website's daily limit less what its day row (its own time zone) already counts.
     *
     * @return array{left: int, limit: int, used_pct: int}
     */
    private function platformQuota(): array
    {
        $limit = app(MailQuotaGate::class)->limit();
        $sent = Cast::int(MailDaily::query()
            ->where('day', app(SiteTime::class)->now()->toDateString())
            ->where('via', 'platform')
            ->value('sent'));

        return [
            'left' => max(0, $limit - $sent),
            'limit' => $limit,
            'used_pct' => $limit > 0 ? min(100, intdiv($sent * 100, $limit)) : 100,
        ];
    }

    public function render(): View
    {
        $platform = $this->scope === 'platform';
        $siteName = $platform ? '' : $this->siteName();
        $platformFrom = $platform ? '' : $this->platformFrom();

        $view = view('admin::livewire.email-settings', [
            'changes' => $this->unsavedCount(),
            'status' => $platform ? null : $this->deliveryStatus(),
            'platformFrom' => $platformFrom,
            'siteName' => $siteName,
            'sign' => $platform ? null : $this->signTab(),
            'keySaved' => ! $platform && $this->keySaved(),
            'quota' => $platform ? null : $this->platformQuota(),
            'sender' => $platform ? null : $this->senderView($siteName, $platformFrom),
        ]);
        $view->layout($platform ? 'admin::layouts.platform' : 'admin::layouts.app');
        $view->title($platform ? __('admin.system_email') : __('admin.email_settings'));

        return $view;
    }
}
