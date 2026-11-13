<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Notifications\TwoFactorDisabledNotification;
use App\Modules\Admin\Support\Locales;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SocialAuth;
use App\Modules\Admin\Theme\AdminChromeResolver;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Identity\Support\UserPreferences;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Notifications\Registry\NotificationKinds;
use App\Modules\Notifications\Support\NotificationPreferences;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteDirectory;
use App\Modules\Tenancy\Context\SiteTime;
use DateTimeInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * My account (Tài khoản của tôi): the signed-in member's own profile, password, two-factor
 * sign-in, linked sign-in accounts, appearance and notification choices. Everything here
 * scopes to auth()->user() only; no role is needed, the member's own password gates the
 * sensitive changes.
 *
 * During a support session the page opens read only: staff signed in as the member see it but
 * every change is refused (SupportSession::forbid). Password checks (the current password, a
 * dialog's re-entry, a two-factor code) are rate limited per member.
 */
#[Layout('admin::layouts.app')]
final class Account extends Component
{
    /** The sections the screen shows (the sessions section waits for a per-member session index). */
    public const array TABS = ['profile', 'security', 'linked', 'prefs'];

    public const int NAME_MAX = 120;

    public const int PASSWORD_MIN = 10;

    /** Wrong passwords or codes allowed per member before a pause of DECAY seconds. */
    public const int ATTEMPTS = 5;

    public const int DECAY = 300;

    /** The pending two-factor secret lives in the session, never in the page's public state. */
    private const string TFA_SESSION = 'account.tfa_secret';

    /**
     * Kinds shown in the notification table and who receives each (a permission, `owner` for the
     * site owner role, null for everyone). Kinds the platform does not send yet stay out.
     */
    private const array NOTICE_AUDIENCE = [
        'workflow.pending_review' => ['entries.review', 'entries.approve'],
        'workflow.assigned' => ['entries.review', 'entries.approve'],
        'workflow.returned' => ['entries.submit'],
        'workflow.published' => ['entries.submit'],
        'lead.new' => ['forms.view'],
        'security.two_factor_disabled' => null,
        'security.support_sign_in' => 'owner',
        'security.domain_taken_over' => ['domains.manage'],
        'system.domain_problem' => ['domains.manage'],
    ];

    /** Kinds whose sender also mails (the only ones with an e-mail switch). */
    private const array MAILED = ['system.domain_problem'];

    #[Url(except: 'profile')]
    public string $tab = 'profile';

    public string $profileName = '';

    /** The saved name, so the page can tell an unsaved edit ("Chưa lưu"). */
    #[Locked]
    public string $savedName = '';

    #[Locked]
    public string $profileSavedAt = '';

    /** Which preferences card shows "Đã lưu — áp dụng ngay": '' (none yet), 'ui' or 'ntf'. */
    #[Locked]
    public string $savedNote = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    #[Locked]
    public bool $passwordChanged = false;

    /** 0 = idle, 1 = scan the code, 2 = type the code, 3 = keep the recovery codes. */
    #[Locked]
    public int $tfaStep = 0;

    public string $tfaCode = '';

    /** @var list<string> shown once, right after they are made */
    #[Locked]
    public array $recoveryCodes = [];

    public bool $dialogOpen = false;

    /** '' | 'regen' | 'codes' | 'disable' | 'unlink' */
    #[Locked]
    public string $dialog = '';

    #[Locked]
    public string $dialogProvider = '';

    public string $confirmPassword = '';

    public function boot(): void
    {
        // The livewire/update endpoint bypasses route middleware: re-assert a signed-in member
        // on every request. Each change also scopes to auth()->user().
        abort_unless(auth()->user() instanceof User, 403);
    }

    public function mount(): void
    {
        $this->profileName = $this->savedName = $this->member()->name;
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'profile';
        $this->tfaStep = $this->pendingSecret() !== null && $this->member()->two_factor_confirmed_at === null ? 1 : 0;
    }

    public function updatedTab(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'profile';
    }

    // ───────────────────────────── profile ─────────────────────────────

    /** Checked when the member leaves the field. */
    public function updatedProfileName(): void
    {
        $this->resetErrorBag('profileName');
        $this->checkName();
    }

    public function saveProfile(): void
    {
        $this->writable();
        $this->resetErrorBag('profileName');
        if (! $this->checkName()) {
            return;
        }

        $user = $this->member();
        $user->forceFill(['name' => trim($this->profileName)])->save();
        $this->profileName = $this->savedName = $user->name;
        $this->profileSavedAt = app(SiteTime::class)->now()->format('H:i');
    }

    public function resetProfile(): void
    {
        $this->profileName = $this->member()->name;
        $this->resetErrorBag('profileName');
    }

    // ───────────────────────────── password ─────────────────────────────

    /**
     * Change the member's own password, gated by the current one. Rotating the hash signs out
     * every other session (AuthenticateSession); this one is re-seeded so the member stays in.
     */
    public function changePassword(): void
    {
        $this->writable();
        $this->passwordChanged = false;
        $this->resetErrorBag(['current_password', 'password', 'password_confirmation']);
        $user = $this->member();

        $errors = $this->passwordErrors();
        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError($field, $message);
            }

            return;
        }

        if (! $this->passwordMatches($user, $this->current_password, 'current_password', __('admin.account.pw_current_wrong'))) {
            return;
        }

        if (Validator::make(['password' => $this->password], ['password' => [Password::defaults()]])->fails()) {
            $this->addError('password', __('admin.account.pw_breached'));

            return;
        }

        $user->forceFill(['password' => Hash::make($this->password)])->save();
        session()->put('password_hash_'.Cast::string(config('auth.defaults.guard')), $user->getAuthPassword());
        Audit::record('auth.password.changed', ['actor_id' => $user->getKey(), 'actor_label' => $user->email]);

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->passwordChanged = true;
    }

    // ───────────────────────────── two-factor ─────────────────────────────

    public function startTwoFactor(): void
    {
        $this->writable();
        if ($this->member()->two_factor_confirmed_at !== null) {
            return;
        }

        session()->put(self::TFA_SESSION, app(TwoFactorService::class)->generateSecret());
        $this->tfaStep = 1;
        $this->reset('tfaCode', 'recoveryCodes');
        $this->resetErrorBag('tfaCode');
    }

    public function twoFactorNext(): void
    {
        $this->writable();
        if ($this->tfaStep === 1 && $this->pendingSecret() !== null) {
            $this->tfaStep = 2;
        }
    }

    public function twoFactorBack(): void
    {
        if ($this->tfaStep === 2) {
            $this->tfaStep = 1;
            $this->resetErrorBag('tfaCode');
        }
    }

    public function cancelTwoFactor(): void
    {
        session()->forget(self::TFA_SESSION);
        $this->tfaStep = 0;
        $this->reset('tfaCode');
        $this->resetErrorBag('tfaCode');
    }

    public function confirmTwoFactor(): void
    {
        $this->writable();
        $this->resetErrorBag('tfaCode');
        $user = $this->member();
        $secret = $this->pendingSecret();
        if ($this->tfaStep !== 2 || $secret === null || $user->two_factor_confirmed_at !== null) {
            return;
        }

        $code = (string) preg_replace('/\s+/', '', $this->tfaCode);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            $this->addError('tfaCode', __('admin.account.tfa_code_format'));

            return;
        }

        $key = 'account-2fa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            $this->addError('tfaCode', __('admin.account.throttled', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        if (! app(TwoFactorService::class)->enable($user, $secret, $code)) {
            RateLimiter::hit($key, self::DECAY);
            $this->addError('tfaCode', __('admin.account.tfa_code_wrong'));

            return;
        }

        RateLimiter::clear($key);
        session()->forget(self::TFA_SESSION);
        Audit::record('auth.2fa.enabled', ['actor_id' => $user->getKey(), 'actor_label' => $user->email]);

        $this->recoveryCodes = Cast::strings($user->refresh()->two_factor_recovery_codes);
        $this->tfaStep = 3;
        $this->reset('tfaCode');
    }

    /** "Xong" after the member confirmed they kept the codes: they are never shown again. */
    public function finishTwoFactor(): void
    {
        $this->tfaStep = 0;
        $this->reset('recoveryCodes');
    }

    // ───────────────────────────── dialogs ─────────────────────────────

    /** Open a confirm dialog: regenerate codes / turn two-factor off (both ask for the password) or unlink a provider. */
    public function openDialog(string $kind, string $provider = ''): void
    {
        $this->writable();
        $user = $this->member();
        $twoFactorOn = $user->two_factor_confirmed_at !== null;

        $allowed = match ($kind) {
            'regen', 'disable' => $twoFactorOn,
            'unlink' => $user->socialIdentities()->where('provider', $provider)->exists(),
            default => false,
        };
        if (! $allowed) {
            return;
        }

        $this->dialog = $kind;
        $this->dialogProvider = $kind === 'unlink' ? $provider : '';
        $this->reset('confirmPassword', 'recoveryCodes');
        $this->resetErrorBag('confirmPassword');
        $this->dialogOpen = true;
    }

    public function closeDialog(): void
    {
        $this->dialogOpen = false;
        $this->dialog = '';
        $this->dialogProvider = '';
        $this->reset('confirmPassword', 'recoveryCodes');
        $this->resetErrorBag('confirmPassword');
    }

    public function regenerateRecoveryCodes(): void
    {
        $this->writable();
        $this->resetErrorBag('confirmPassword');
        $user = $this->member();
        if ($this->dialog !== 'regen' || $user->two_factor_confirmed_at === null) {
            return;
        }
        if (! $this->passwordMatches($user, $this->confirmPassword, 'confirmPassword', __('admin.account.dlg_pw_wrong'))) {
            return;
        }

        $codes = app(TwoFactorService::class)->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();
        Audit::record('auth.2fa.recovery_regenerated', ['actor_id' => $user->getKey(), 'actor_label' => $user->email]);

        $this->recoveryCodes = $codes;
        $this->dialog = 'codes';
        $this->reset('confirmPassword');
    }

    public function disableTwoFactor(): void
    {
        $this->writable();
        $this->resetErrorBag('confirmPassword');
        $user = $this->member();
        if ($this->dialog !== 'disable' || $user->two_factor_confirmed_at === null) {
            return;
        }
        if (! $this->passwordMatches($user, $this->confirmPassword, 'confirmPassword', __('admin.account.dlg_pw_wrong'))) {
            return;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        // Weakening sign-in is a security event: on record, and the member is told in the bell
        // and by mail (a locked notice), so a hijacked session cannot do it quietly.
        Audit::record('auth.2fa.disabled', ['actor_id' => $user->getKey(), 'actor_label' => $user->email]);
        $user->notify((new TwoFactorDisabledNotification(
            app(SiteTime::class)->now()->format('d/m/Y H:i'),
        ))->forCurrentSite());

        $this->closeDialog();
        $this->tfaStep = 0;
    }

    public function unlinkProvider(): void
    {
        $this->writable();
        $user = $this->member();
        if ($this->dialog !== 'unlink') {
            return;
        }

        $provider = $this->dialogProvider;
        $links = $user->socialIdentities()->pluck('provider')->all();
        // Never remove the last way in: a member with no password keeps their only link.
        if (! in_array($provider, $links, true) || ($this->lacksPassword($user) && count($links) === 1)) {
            $this->closeDialog();

            return;
        }

        $user->socialIdentities()->where('provider', $provider)->delete();
        Audit::record('auth.social.unlinked', [
            'actor_id' => $user->getKey(),
            'actor_label' => $user->email,
            'meta' => ['provider' => $provider],
        ]);

        $this->closeDialog();
    }

    // ───────────────────────────── preferences ─────────────────────────────

    public function setLocale(string $code): void
    {
        $this->writable();
        if (! in_array($code, Locales::codes(), true)) {
            return;
        }

        session(['locale' => $code]);
        UserPreferences::set($this->member(), ['locale'], $code);

        // The whole admin changes language: reload the page in it.
        $this->redirectRoute('admin.account', ['tab' => 'prefs']);
    }

    /** Light, dark, or follow the device ('auto', the member's own choice only). */
    public function setScheme(string $scheme): void
    {
        $this->writable();
        if (! in_array($scheme, [...AdminChromeResolver::FIELDS['scheme'], AdminChromeResolver::SCHEME_AUTO], true)) {
            return;
        }

        $this->savePreference(['theme', 'scheme'], $scheme);
    }

    public function setTextSize(string $size): void
    {
        $this->writable();
        if (! in_array($size, AdminChromeResolver::FIELDS['text_size'], true)) {
            return;
        }

        $this->savePreference(['theme', 'text_size'], $size);
    }

    /** "Về mặc định": drop the member's own size, the site default applies again. */
    public function resetTextSize(): void
    {
        $this->writable();
        UserPreferences::forget($this->member(), ['theme', 'text_size']);
        $this->repaint();
    }

    public function setMotion(string $motion): void
    {
        $this->writable();
        if (! in_array($motion, AdminChromeResolver::FIELDS['motion'], true)) {
            return;
        }

        $this->savePreference(['theme', 'motion'], $motion);
    }

    /**
     * Turn one notification kind on or off for one channel. Locked kinds, kinds the member does
     * not receive, and channels the kind is not sent on are refused.
     */
    public function setNotification(string $kind, string $channel, bool $on): void
    {
        $this->writable();
        $user = $this->member();
        $spec = NotificationKinds::all()[$kind] ?? null;

        $channelOk = $channel === 'in_app' || ($channel === 'mail' && in_array($kind, self::MAILED, true));
        if ($spec === null || $spec['locked'] || ! $channelOk || ! $this->receives($user, $kind)) {
            return;
        }

        UserPreferences::set($user, ['notifications', $kind, $channel], $on);
        UserPreferences::reload($user);
        $this->savedNote = 'ntf';
    }

    public function render(): View
    {
        $user = $this->member();
        $readOnly = app(SupportSession::class)->active();
        $twoFactorOn = $user->two_factor_confirmed_at !== null;
        $secret = $this->tfaStep === 1 || $this->tfaStep === 2 ? $this->pendingSecret() : null;

        $view = view('admin::livewire.account', [
            'user' => $user,
            'readOnly' => $readOnly,
            'initials' => self::initials($user->name),
            'roleLabel' => RoleLabel::topOf($user),
            'siteName' => app(CurrentSite::class)->hasId() ? app(SiteDirectory::class)->nameOfCurrent(app(CurrentSite::class)) : '',
            'twoFactorOn' => $twoFactorOn,
            'twoFactorSince' => $twoFactorOn ? app(SiteTime::class)->forDisplay($user->two_factor_confirmed_at)?->format('d/m/Y') : null,
            'codesLeft' => count(Cast::strings($user->two_factor_recovery_codes)),
            'tfaQr' => $secret !== null ? app(TwoFactorService::class)->qrSvg($user, $secret) : null,
            'tfaKey' => $secret !== null ? implode(' ', str_split($secret, 4)) : null,
            'tfaRawKey' => $secret,
            'providers' => $this->providerRows($user),
            'locales' => Locales::available(),
            'prefs' => $this->preferenceState($user),
            'noticeGroups' => $this->noticeGroups($user),
        ]);
        $view->title(__('admin.account.title'));

        return $view;
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private function member(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Never on someone's behalf: staff signed in for support only look. */
    private function writable(): void
    {
        app(SupportSession::class)->forbid();
    }

    private function pendingSecret(): ?string
    {
        $secret = session(self::TFA_SESSION);

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function checkName(): bool
    {
        $name = trim($this->profileName);
        if ($name === '') {
            $this->addError('profileName', __('admin.account.name_required'));

            return false;
        }

        $over = mb_strlen($name) - self::NAME_MAX;
        if ($over > 0) {
            $this->addError('profileName', __('admin.account.name_too_long', ['count' => $over]));

            return false;
        }

        return true;
    }

    /** @return array<string, string> field => message, in the order the board checks them */
    private function passwordErrors(): array
    {
        $errors = [];
        if ($this->current_password === '') {
            $errors['current_password'] = __('admin.account.pw_current_required');
        }

        $length = mb_strlen($this->password);
        if ($length === 0) {
            $errors['password'] = __('admin.account.pw_new_required');
        } elseif ($length < self::PASSWORD_MIN) {
            $errors['password'] = __('admin.account.pw_new_short', ['count' => self::PASSWORD_MIN - $length]);
        } elseif ($this->current_password !== '' && $this->password === $this->current_password) {
            $errors['password'] = __('admin.account.pw_new_same');
        }

        if ($this->password_confirmation === '') {
            $errors['password_confirmation'] = __('admin.account.pw_confirm_required');
        } elseif ($this->password_confirmation !== $this->password) {
            $errors['password_confirmation'] = __('admin.account.pw_confirm_mismatch');
        }

        return $errors;
    }

    /** Check the member's own password, at most ATTEMPTS wrong tries per DECAY seconds. */
    private function passwordMatches(User $user, string $password, string $field, string $wrong): bool
    {
        $key = 'account-reauth:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            $this->addError($field, __('admin.account.throttled', ['seconds' => RateLimiter::availableIn($key)]));

            return false;
        }

        if ($password === '' || ! Hash::check($password, $user->getAuthPassword())) {
            RateLimiter::hit($key, self::DECAY);
            $this->addError($field, $password === '' ? __('admin.account.dlg_pw_required') : $wrong);

            return false;
        }

        RateLimiter::clear($key);

        return true;
    }

    private function lacksPassword(User $user): bool
    {
        return $user->getAuthPassword() === '';
    }

    /** @param  non-empty-list<string>  $path */
    private function savePreference(array $path, string $value): void
    {
        UserPreferences::set($this->member(), $path, $value);
        $this->repaint();
    }

    /** Repaint <html> at once with the member's new appearance (app.js `admin-chrome-changed`). */
    private function repaint(): void
    {
        $user = $this->member();
        UserPreferences::reload($user);
        $this->dispatch('admin-chrome-changed', attributes: app(AdminChromeResolver::class)->attributes($user));
        $this->savedNote = 'ui';
    }

    private function receives(User $user, string $kind): bool
    {
        if (! array_key_exists($kind, self::NOTICE_AUDIENCE)) {
            return false;
        }

        $audience = self::NOTICE_AUDIENCE[$kind];
        if ($audience === null) {
            return true;
        }
        if ($audience === 'owner') {
            return $user->hasRole('owner');
        }

        foreach ($audience as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sign-in providers a member can link: those that support linking and are switched on
     * for this site, plus any the member already linked (so a link can always be removed).
     *
     * @return list<array{key: string, label: string, linked: bool, email: ?string, since: ?string, lastWay: bool}>
     */
    private function providerRows(User $user): array
    {
        $links = $user->socialIdentities()->get(['provider', 'email', 'created_at'])->keyBy('provider');
        $social = app(SocialAuth::class);
        $noPassword = $this->lacksPassword($user);
        $time = app(SiteTime::class);

        $rows = [];
        foreach (Cast::arr(config('oauth.providers')) as $key => $cfg) {
            $key = Cast::string($key);
            $cfg = Cast::arr($cfg);
            if (! in_array('connect', Cast::strings($cfg['supports'] ?? null), true)) {
                continue;
            }

            $link = $links->get($key);
            if ($link === null && ! $social->enabled($key)) {
                continue;
            }

            $created = $link?->getAttribute('created_at');
            $rows[] = [
                'key' => $key,
                'label' => Cast::string($cfg['label'] ?? null, $key),
                'linked' => $link !== null,
                'email' => $link !== null ? Cast::string($link->getAttribute('email')) : null,
                'since' => $created instanceof DateTimeInterface ? $time->forDisplay($created)?->format('m/Y') : null,
                'lastWay' => $link !== null && $noPassword && $links->count() === 1,
            ];
        }

        return $rows;
    }

    /**
     * @return array{locale: string, scheme: string, textSize: string, ownTextSize: bool, siteTextSize: string, motion: string}
     */
    private function preferenceState(User $user): array
    {
        $own = Cast::arr($user->preferences['theme'] ?? null);
        $resolved = app(AdminChromeResolver::class)->resolve($user);
        $site = Cast::arr(app(SettingsRepository::class)->get('admin_theme'));
        $siteSize = Cast::string($site['text_size'] ?? null);

        return [
            'locale' => app()->getLocale(),
            'scheme' => AdminChromeResolver::followsDevice($user) ? AdminChromeResolver::SCHEME_AUTO : $resolved['scheme'],
            'textSize' => $resolved['text_size'],
            'ownTextSize' => in_array($own['text_size'] ?? null, AdminChromeResolver::FIELDS['text_size'], true),
            'siteTextSize' => in_array($siteSize, AdminChromeResolver::FIELDS['text_size'], true) ? $siteSize : 'md',
            'motion' => $resolved['motion'],
        ];
    }

    /**
     * @return list<array{group: string, rows: list<array{kind: string, locked: bool, inApp: bool, mail: ?bool}>}>
     */
    private function noticeGroups(User $user): array
    {
        $groups = [];
        foreach (NotificationKinds::all() as $kind => $spec) {
            if (! $this->receives($user, $kind)) {
                continue;
            }

            $groups[$spec['group']][] = [
                'kind' => $kind,
                'locked' => $spec['locked'],
                'inApp' => NotificationPreferences::wants($user, $kind, 'in_app'),
                // null = this kind sends no e-mail yet ("Để sau")
                'mail' => $spec['locked'] || in_array($kind, self::MAILED, true) ? NotificationPreferences::wants($user, $kind, 'mail') : null,
            ];
        }

        $out = [];
        foreach (['workflow', 'lead', 'security', 'system'] as $group) {
            if (isset($groups[$group])) {
                $out[] = ['group' => $group, 'rows' => $groups[$group]];
            }
        }

        return $out;
    }

    /** The last two words' first letters ("Nguyễn Ngọc Anh" → "NA"), or the first two letters of one word. */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) >= 2) {
            $initials = mb_substr($words[count($words) - 2], 0, 1).mb_substr($words[count($words) - 1], 0, 1);
        } else {
            $initials = mb_substr($words[0] ?? '?', 0, 2);
        }

        return Str::upper($initials);
    }
}
