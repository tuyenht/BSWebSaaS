<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\ShowsSettingsToast;
use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use App\Modules\Admin\Support\MailBrand;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Models\Site;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
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
    private const array TRACKED = ['provider', 'smtpHost', 'smtpPort', 'smtpEncryption', 'smtpUsername', 'smtpPassword',
        'oauthClientId', 'oauthClientSecret', 'fromAddress', 'fromName', 'replyTo'];

    /** The fields of «Chữ ký & thương hiệu» (the signature is compared as it would be stored). */
    private const array BRAND_TRACKED = ['brandColor', 'logoKey', 'sigLayout', 'sigHtml'];

    /** The tabs of a website's page, in the board's order («Mẫu thư» comes with the mail templates). */
    public const array TABS = ['send', 'sign'];

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
    }

    /** Back to the saved setup (the save bar's "Bỏ thay đổi"); a secret typed but not saved is dropped. */
    public function resetChanges(): void
    {
        $this->reset(...self::TRACKED, ...self::BRAND_TRACKED);
        $this->resetErrorBag();
        $this->testResult = null;
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

        // Only what changed is checked and written: a signature saves without the outbox, and back.
        $changed = $this->unsavedFields();
        $brandChanged = $this->scope === 'site' && array_intersect($changed, self::BRAND_TRACKED) !== [];
        $senderChanged = ! $brandChanged || array_diff($changed, self::BRAND_TRACKED) !== [];

        try {
            $this->validate(
                [...($senderChanged ? $this->rules() : []), ...($brandChanged ? $this->brandRules() : [])],
                [
                    'smtpPort.in' => __('admin.smtp_port_mail_only', ['ports' => implode(', ', MailHostGuard::PORTS)]),
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
        if (! $senderChanged) {
            $this->loadBrand();
            $this->rememberSaved();
            $this->sendSignatureToEditor();
            session()->flash('status', __('admin.saved'));

            return true;
        }

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
        // Only the rows whose fields changed: a colour saved from an older page leaves a newer signature be.
        $changed = $this->unsavedFields();
        $brandRow = array_intersect(['brandColor', 'logoKey'], $changed) !== [];
        $logoId = null;
        if ($brandRow && $this->logoKey !== '') {
            $logoId = Media::query()->where('storage_key', $this->logoKey)->where('mime', 'like', 'image/%')->value('id');
            if (! is_int($logoId)) {
                $this->addError('logoKey', __('admin.em_logo_gone'));

                return false;
            }
        }

        $repo = app(SettingsRepository::class);
        if ($brandRow) {
            $repo->put('email', 'brand', ['logo_media_id' => $logoId, 'color' => $this->brandColor !== '' ? $this->brandColor : null]);
        }
        if (array_intersect(['sigLayout', 'sigHtml'], $changed) !== []) {
            $repo->put('email', 'signature', ['layout' => $this->sigLayout, 'html' => app(MailBrand::class)->clean($this->sigHtml, $this->sigLayout)]);
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
            $this->dispatch('email-signature-image', url: app(MediaUrlResolver::class)->url($media->storage_key, 600),
                alt: Cast::string($media->alt[app()->getLocale()] ?? null));
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

    public function render(): View
    {
        $platform = $this->scope === 'platform';

        $view = view('admin::livewire.email-settings', [
            'changes' => $this->unsavedCount(),
            'status' => $platform ? null : $this->deliveryStatus(),
            'platformFrom' => $platform ? '' : $this->platformFrom(),
            'siteName' => $platform ? '' : $this->siteName(),
            'sign' => $platform ? null : $this->signTab(),
        ]);
        $view->layout($platform ? 'admin::layouts.platform' : 'admin::layouts.app');
        $view->title($platform ? __('admin.system_email') : __('admin.email_settings'));

        return $view;
    }
}
