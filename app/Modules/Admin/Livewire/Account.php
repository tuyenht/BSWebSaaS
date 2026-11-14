<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Notifications\TwoFactorDisabledNotification;
use App\Modules\Admin\Support\EmailChange;
use App\Modules\Admin\Support\Locales;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SignedInDevices;
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
use App\Modules\Shared\Support\ConcurrencyError;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteDirectory;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Support\ContactPhone;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
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
use Throwable;

/**
 * My account (Tài khoản của tôi): the signed-in member's own profile (name, job title, phone),
 * sign-in email (EmailChange), password, two-factor sign-in, signed-in devices, linked sign-in
 * accounts, appearance and
 * notification choices. Everything here
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
    /** The sections the screen shows. */
    public const array TABS = ['profile', 'security', 'sessions', 'linked', 'prefs'];

    public const int NAME_MAX = 120;

    public const int TITLE_MAX = 80;

    /**
     * The country a member's phone is read in: a number typed with a leading 0 is Vietnamese (10
     * digits for a mobile, 11 for a landline), any other is typed with its own +country code. Fixed
     * rather than customers.phone_country_code: the signed rule names Vietnamese numbers, as the
     * floating contact button does.
     */
    private const string PHONE_HOME = '84';

    public const int PASSWORD_MIN = 10;

    /** Wrong passwords or codes allowed per member before a pause of DECAY seconds. */
    public const int ATTEMPTS = 5;

    public const int DECAY = 300;

    /** The pending two-factor secret lives in the session, never in the page's public state. */
    private const string TFA_SESSION = 'account.tfa_secret';

    /** When the setup started; a setup left open longer than TFA_PENDING_SECONDS is dropped. */
    private const string TFA_SESSION_AT = 'account.tfa_secret_at';

    private const int TFA_PENDING_SECONDS = 600;

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

    /** The icon of each kind of device (board 23). */
    private const array DEVICE_ICONS = [
        'windows' => 'ri-computer', 'mac' => 'ri-macbook', 'linux' => 'ri-computer', 'chromeos' => 'ri-computer',
        'iphone' => 'ri-smartphone', 'ipad' => 'ri-tablet', 'android' => 'ri-android', 'android_tablet' => 'ri-tablet',
        'other' => 'ri-device',
    ];

    private const array BROWSER_NAMES = [
        'chrome' => 'Chrome', 'safari' => 'Safari', 'firefox' => 'Firefox', 'edge' => 'Edge',
        'samsung' => 'Samsung Internet', 'opera' => 'Opera', 'coccoc' => 'Cốc Cốc',
    ];

    /** Kinds whose sender also mails (the only ones with an e-mail switch). */
    private const array MAILED = ['system.domain_problem'];

    #[Url(except: 'profile')]
    public string $tab = 'profile';

    public string $profileName = '';

    /** The saved name, so the page can tell an unsaved edit ("Chưa lưu"). */
    #[Locked]
    public string $savedName = '';

    public string $profileTitle = '';

    #[Locked]
    public string $savedTitle = '';

    /** As typed; stored as E.164 and shown back in the national form for a Vietnamese number. */
    public string $profilePhone = '';

    #[Locked]
    public string $savedPhone = '';

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

    /** How many of the member's other devices the password change signed out. */
    #[Locked]
    public int $passwordOthers = 0;

    /** The short message at the bottom of the screen ("Đã đăng xuất …"). */
    #[Locked]
    public string $toast = '';

    #[Locked]
    public string $toastTone = 'success';

    #[Locked]
    public int $toastSeq = 0;

    /** 0 = idle, 1 = scan the code, 2 = type the code, 3 = keep the recovery codes. */
    #[Locked]
    public int $tfaStep = 0;

    public string $tfaCode = '';

    /** @var list<string> shown once, right after they are made */
    #[Locked]
    public array $recoveryCodes = [];

    public bool $dialogOpen = false;

    /** '' | 'enable' | 'regen' | 'codes' | 'disable' | 'unlink' */
    #[Locked]
    public string $dialog = '';

    #[Locked]
    public string $dialogProvider = '';

    public string $confirmPassword = '';

    /** Changing the sign-in email: 0 = closed, 1 = new address + password, 2 = the second step, 3 = the new address's code. */
    #[Locked]
    public int $emailStep = 0;

    /** The second step this change asks for: 'app' (authenticator or recovery code) or 'mail' (a code to the current address). */
    #[Locked]
    public string $emailFactor = '';

    /** Where the code of the current step went (shown in its wording). */
    #[Locked]
    public string $emailSentTo = '';

    public string $newEmail = '';

    public string $emailPassword = '';

    public string $emailFactorCode = '';

    public bool $emailUseRecovery = false;

    public string $emailCode = '';

    public function boot(): void
    {
        // The livewire/update endpoint bypasses route middleware: re-assert a signed-in member
        // on every request. Each change also scopes to auth()->user().
        abort_unless(auth()->user() instanceof User, 403);
    }

    public function mount(): void
    {
        $this->fillProfile($this->member());
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

    public function updatedProfileTitle(): void
    {
        $this->resetErrorBag('profileTitle');
        $this->checkTitle();
    }

    public function updatedProfilePhone(): void
    {
        $this->resetErrorBag('profilePhone');
        $this->checkPhone();
    }

    /**
     * Save the name, job title and phone together, once all three pass. The phone is stored in one
     * international form. A change goes on record with the names of the fields changed only: the
     * values (the phone is personal data) stay out of the long-kept activity log.
     */
    public function saveProfile(): void
    {
        $this->writable();
        $this->resetErrorBag(['profileName', 'profileTitle', 'profilePhone']);
        $nameOk = $this->checkName();
        $titleOk = $this->checkTitle();
        $phone = $this->checkPhone();
        if (! $nameOk || ! $titleOk || $phone === false) {
            return;
        }

        $user = $this->member();
        $title = self::plain($this->profileTitle);
        $user->forceFill([
            'name' => self::plain($this->profileName),
            'job_title' => $title !== '' ? $title : null,
            'phone' => $phone,
        ]);
        $changed = array_keys($user->getDirty());
        $user->save();
        if ($changed !== []) {
            Audit::record('identity.profile.updated', [
                'actor_id' => $user->getKey(),
                'actor_label' => $user->email,
                'meta' => ['fields' => $changed],
            ]);
        }

        $this->fillProfile($user);
        $this->profileSavedAt = app(SiteTime::class)->now()->format('H:i');
    }

    public function resetProfile(): void
    {
        $this->fillProfile($this->member());
        $this->resetErrorBag(['profileName', 'profileTitle', 'profilePhone']);
    }

    // ───────────────────────────── password ─────────────────────────────

    /**
     * Change the member's own password, gated by the current one. Every other device is signed
     * out at once (a support session too, on record) and counted for the message; rotating the
     * hash signs out any session the device index does not know (AuthenticateSession). This one is
     * re-seeded so the member stays in.
     */
    public function changePassword(): void
    {
        $this->writable();
        $this->passwordChanged = false;
        $this->passwordOthers = 0;
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

        // Counted while the index still knows the old password; then the new one is saved.
        $others = $this->signOutOthersQuietly($user);
        $user->forceFill(['password' => Hash::make($this->password)])->save();
        // A new session id with the new password: an id copied before the change is worth nothing.
        session()->regenerate(true);
        session()->put('password_hash_'.Cast::string(config('auth.defaults.guard')), $user->getAuthPassword());
        Audit::record('auth.password.changed', ['actor_id' => $user->getKey(), 'actor_label' => $user->email]);

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->passwordChanged = true;
        $this->passwordOthers = $others;
    }

    // ───────────────────────────── signed-in devices ─────────────────────────────

    /**
     * "Đăng xuất thiết bị này" on one of the member's other devices: its session ends at once. Only
     * a device of the signed-in member, never the one asking; $key is the row's opaque key.
     */
    public function signOutDevice(string $key): void
    {
        $this->writable();
        $user = $this->member();
        if (! SignedInDevices::isKey($key)) {
            $this->notify(__('admin.account.ses_gone'), 'info');

            return;
        }

        try {
            $ended = app(SignedInDevices::class)->signOut($user, session()->getId(), $key);
        } catch (Throwable $e) {
            $this->failed($e);

            return;
        }

        if ($ended === null) {
            $this->notify(__('admin.account.ses_gone'), 'info');

            return;
        }

        if ($ended['kind'] === SignedInDevices::KIND_SUPPORT) {
            // its own record is the support session's end row, on the session's website
            $this->notify(__('admin.account.ses_out_support'));

            return;
        }
        $this->recordSignOut($user, 1);
        $this->notify(__('admin.account.ses_out_one', ['device' => self::deviceLabel($ended['device'])]));
    }

    /** "Đăng xuất mọi thiết bị khác": every other session ends, a support session included. */
    public function signOutOtherDevices(): void
    {
        $this->writable();
        $user = $this->member();

        try {
            $ended = app(SignedInDevices::class)->signOutOthers($user, session()->getId());
        } catch (Throwable $e) {
            $this->failed($e);

            return;
        }

        if ($ended['members'] > 0) {
            $this->recordSignOut($user, $ended['members']);
            $this->notify(__('admin.account.ses_out_all', ['count' => $ended['members']]));
        } elseif ($ended['support'] > 0) {
            // only a support session was signed in as the member: its end row is its record
            $this->notify(__('admin.account.ses_out_support'));
        }
    }

    public function dismissToast(?int $seq = null): void
    {
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toast = '';
        }
    }

    // ───────────────────────────── two-factor ─────────────────────────────

    /**
     * Start the setup from the "Bật xác thực 2 lớp" dialog, once the member typed their password
     * again: adding a sign-in factor (and getting its recovery codes) must not be open to someone
     * who only holds a session.
     */
    public function startTwoFactor(): void
    {
        $this->writable();
        $this->resetErrorBag('confirmPassword');
        $user = $this->member();
        if ($this->dialog !== 'enable' || $user->two_factor_confirmed_at !== null) {
            return;
        }
        if (! $this->passwordMatches($user, $this->confirmPassword, 'confirmPassword', __('admin.account.dlg_pw_wrong'))) {
            return;
        }

        $this->closeDialog();
        session()->put(self::TFA_SESSION, app(TwoFactorService::class)->generateSecret());
        session()->put(self::TFA_SESSION_AT, now()->getTimestamp());
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
        session()->forget([self::TFA_SESSION, self::TFA_SESSION_AT]);
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
        session()->forget([self::TFA_SESSION, self::TFA_SESSION_AT]);
        // A new sign-in factor is a security event too: the member hears of it in the bell and by
        // mail (the same locked notice as turning it off), so it cannot be added quietly.
        Audit::record('auth.2fa.enabled', ['actor_id' => $user->getKey(), 'actor_label' => $user->email]);
        $user->notify((new TwoFactorDisabledNotification(
            app(SiteTime::class)->now()->format('d/m/Y H:i'),
            turnedOn: true,
        ))->forCurrentSite());

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

    /** Open a confirm dialog: turn two-factor on / regenerate codes / turn it off (all ask for the password) or unlink a provider. */
    public function openDialog(string $kind, string $provider = ''): void
    {
        $this->writable();
        $user = $this->member();
        $twoFactorOn = $user->two_factor_confirmed_at !== null;

        $allowed = match ($kind) {
            'enable' => ! $twoFactorOn,
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

    // ───────────────────────────── sign-in email ─────────────────────────────

    /**
     * "Đổi email": open the change at its first step. An account without a password cannot
     * change its email (it would pass with no secret at all); it sets one first.
     */
    public function openEmailChange(): void
    {
        $this->writable();
        $user = $this->member();
        app(EmailChange::class)->cancel($user, session()->getId());
        $this->resetEmailChange();
        if ($this->lacksPassword($user)) {
            $this->notify(__('admin.account.em_no_password'), 'info');

            return;
        }

        $this->emailStep = 1;
    }

    /** Checked when the member leaves the field. */
    public function updatedNewEmail(): void
    {
        $this->resetErrorBag('newEmail');
        if (trim($this->newEmail) !== '') {
            $this->checkNewEmail($this->member());
        }
    }

    /**
     * Step 1: the new address and the password. Whether another account holds the address is not
     * told here (nor anywhere before its mailbox code is typed), so the change cannot be used to
     * find out who has an account. Then the second step: the app, or a code to the current address.
     */
    public function startEmailChange(): void
    {
        $this->writable();
        $this->resetErrorBag(['newEmail', 'emailPassword']);
        $user = $this->member();
        if ($this->emailStep !== 1 || $this->lacksPassword($user)) {
            return;
        }

        $new = $this->checkNewEmail($user);
        if ($this->emailPassword === '') {
            $this->addError('emailPassword', __('admin.account.dlg_pw_required'));

            return;
        }
        if ($new === null || ! $this->passwordMatches($user, $this->emailPassword, 'emailPassword', __('admin.account.dlg_pw_wrong'))) {
            return;
        }

        $change = app(EmailChange::class);
        $this->emailFactor = $change->begin($user, session()->getId(), $new);
        $this->newEmail = $new;
        $this->reset('emailPassword', 'emailFactorCode', 'emailUseRecovery', 'emailCode');
        $this->emailSentTo = '';
        if ($this->emailFactor === EmailChange::FACTOR_MAIL) {
            $this->emailSentTo = $user->email;
            if ($change->sendCode($user, session()->getId(), toNew: false) !== EmailChange::SENT) {
                $this->notify(__('admin.account.em_wait'), 'info');
            }
        }
        $this->emailStep = 2;
    }

    /** Step 2: the code of the second step; once it passes, a code goes to the new address. */
    public function verifyEmailFactor(): void
    {
        $this->writable();
        $this->resetErrorBag('emailFactorCode');
        $user = $this->member();
        if ($this->emailStep !== 2) {
            return;
        }

        $recovery = $this->emailUseRecovery && $this->emailFactor === EmailChange::FACTOR_APP;
        $code = $recovery ? trim($this->emailFactorCode) : (string) preg_replace('/\s+/', '', $this->emailFactorCode);
        if ($recovery ? $code === '' : preg_match('/^\d{6}$/', $code) !== 1) {
            $this->addError('emailFactorCode', $recovery ? __('admin.account.em_recovery_wrong') : __('admin.account.tfa_code_format'));

            return;
        }

        $change = app(EmailChange::class);
        $result = $change->passFactor($user, session()->getId(), $code, $recovery);
        if ($result !== EmailChange::PASSED) {
            $this->emailCodeRefused('emailFactorCode', $result, $user, $recovery);

            return;
        }

        $this->emailSentTo = (string) $change->newAddress($user, session()->getId());
        $this->reset('emailFactorCode', 'emailUseRecovery', 'emailCode');
        $this->emailStep = 3;
        if ($change->sendCode($user, session()->getId(), toNew: true) !== EmailChange::SENT) {
            $this->notify(__('admin.account.em_wait'), 'info');
        }
    }

    /** "Gửi lại mã": the code of the current step again, after the cooldown and within the hourly caps. */
    public function resendEmailCode(): void
    {
        $this->writable();
        $user = $this->member();
        $toNew = $this->emailStep === 3;
        if (! $toNew && ! ($this->emailStep === 2 && $this->emailFactor === EmailChange::FACTOR_MAIL)) {
            return;
        }

        match (app(EmailChange::class)->sendCode($user, session()->getId(), $toNew)) {
            EmailChange::SENT => $this->notify(__('admin.account.em_resent', ['email' => $this->emailSentTo])),
            EmailChange::WAIT => $this->notify(__('admin.account.em_wait'), 'info'),
            default => $this->emailChangeGone(),
        };
        $this->reset($toNew ? 'emailCode' : 'emailFactorCode');
    }

    /**
     * Step 3: the code mailed to the new address. The account moves to the address that code went
     * to (never the field as it reads now), the old address is told, every other device is signed
     * out (it signed in under the old address; a support session too) and this one gets a new
     * session id.
     */
    public function confirmEmailChange(): void
    {
        $this->writable();
        $this->resetErrorBag('emailCode');
        $user = $this->member();
        if ($this->emailStep !== 3) {
            return;
        }

        $code = (string) preg_replace('/\s+/', '', $this->emailCode);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            $this->addError('emailCode', __('admin.account.tfa_code_format'));

            return;
        }

        $result = app(EmailChange::class)->confirm($user, session()->getId(), $code);
        if ($result === EmailChange::TAKEN) {
            $this->addError('emailCode', __('admin.account.em_taken'));

            return;
        }
        if ($result !== EmailChange::CHANGED) {
            $this->emailCodeRefused('emailCode', $result, $user, false);

            return;
        }

        // Unlike a password change nothing else signs those devices out, so a failure is said out loud.
        // In its own savepoint: a failed statement there must not abort the request's transaction,
        // which still has to commit the change.
        try {
            $others = DB::transaction(fn (): int => app(SignedInDevices::class)->signOutOthers($user, session()->getId())['members']);
        } catch (Throwable $e) {
            if (ConcurrencyError::is($e)) {
                throw $e;
            }
            report($e);
            $others = null;
        }
        session()->regenerate(true);
        $this->resetEmailChange();
        match (true) {
            $others === null => $this->notify(__('admin.account.em_done_others_kept'), 'error'),
            $others > 0 => $this->notify(__('admin.account.em_done_others', ['count' => $others])),
            default => $this->notify(__('admin.account.em_done')),
        };
    }

    /** "Quay lại": back to the address, which starts the change again (password and second step too). */
    public function backEmailChange(): void
    {
        $this->writable();
        if ($this->emailStep < 2) {
            return;
        }

        app(EmailChange::class)->cancel($this->member(), session()->getId());
        $new = $this->newEmail;
        $this->resetEmailChange();
        $this->newEmail = $new;
        $this->emailStep = 1;
    }

    public function cancelEmailChange(): void
    {
        $this->writable();
        app(EmailChange::class)->cancel($this->member(), session()->getId());
        $this->resetEmailChange();
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
            ...($this->tab === 'sessions' ? $this->deviceRows($user) : ['devices' => [], 'deviceOthers' => 0]),
        ]);
        $view->title(__('admin.account.title'));

        return $view;
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /**
     * The rows of "Phiên đăng nhập": this device first, then the others by last use. A support
     * session is not listed yet (its row waits for its design); ending every other device still
     * ends it, so it counts among the others (deviceOthers): the member is never told nobody else is
     * signed in while staff are. During a support session the staff member's own session is not
     * shown either.
     *
     * @return array{devices: list<array{key: string, current: bool, icon: string, device: string, meta: string, out: string}>, deviceOthers: int}
     */
    private function deviceRows(User $user): array
    {
        $current = app(CurrentSite::class);
        $support = app(SupportSession::class)->active();
        $all = app(SignedInDevices::class)->rows($user, session()->getId(), request()->userAgent(), $current->hasId() ? $current->id() : null);
        $others = count(array_filter($all, fn (array $row): bool => ! $row['current']));
        $rows = array_values(array_filter(
            $all,
            // staff signed in for support see the member's devices, never their own as "Thiết bị này"
            fn (array $row): bool => $row['kind'] === SignedInDevices::KIND_MEMBER && ! ($row['current'] && $support),
        ));

        $siteIds = array_values(array_unique(array_filter(array_column($rows, 'site_id'), is_int(...))));
        $names = $siteIds === [] ? [] : Site::withTrashed()->whereIn('id', $siteIds)->pluck('name', 'id')->all();

        $shown = [];
        foreach ($rows as $row) {
            $device = self::deviceLabel($row['device']);
            $browser = self::browserLabel($row['browser']);
            $site = $row['site_id'] === null
                ? __('admin.account.ses_platform')
                : (trim(Cast::string($names[$row['site_id']] ?? null)) ?: __('admin.account.ses_site_unknown'));
            $shown[] = [
                'key' => $row['key'],
                'current' => $row['current'],
                'icon' => self::DEVICE_ICONS[$row['device']] ?? 'ri-device',
                'device' => $device,
                'meta' => implode(' · ', [$browser, $site, $row['current'] ? __('admin.account.ses_active') : $this->lastUsed($row['last'])]),
                'out' => __('admin.account.ses_out_label', ['device' => $device, 'browser' => $browser]),
            ];
        }

        return ['devices' => $shown, 'deviceOthers' => $others];
    }

    /** When a device was last used, in the site's time: the index knows it to THROTTLE seconds. */
    private function lastUsed(int $timestamp): string
    {
        $time = app(SiteTime::class);
        $at = $time->forDisplay(CarbonImmutable::createFromTimestamp($timestamp));
        $now = $time->now();
        if ($at === null) {
            return '';
        }

        $seconds = max(0, $now->getTimestamp() - $at->getTimestamp());

        return match (true) {
            $seconds < SignedInDevices::THROTTLE => __('admin.account.ses_active'),
            $seconds < 3600 => __('admin.account.ses_minutes', ['n' => intdiv($seconds, 60)]),
            $at->isSameDay($now) => __('admin.account.ses_hours', ['n' => intdiv($seconds, 3600)]),
            $at->isSameDay($now->subDay()) => __('admin.account.ses_yesterday', ['time' => $at->format('H:i')]),
            default => __('admin.account.ses_on', ['date' => $at->format('d/m/Y'), 'time' => $at->format('H:i')]),
        };
    }

    private static function deviceLabel(string $device): string
    {
        return __('admin.account.ses_dev_'.(in_array($device, SignedInDevices::DEVICES, true) ? $device : 'other'));
    }

    /** Browser names are names: written as their makers write them, in every language. */
    private static function browserLabel(string $browser): string
    {
        return self::BROWSER_NAMES[$browser] ?? __('admin.account.ses_browser_other');
    }

    /**
     * Sign the other devices out after a password change. The password is already changed, so a
     * failure here only loses the count: rotating the hash still signs those sessions out.
     */
    private function signOutOthersQuietly(User $user): int
    {
        try {
            return app(SignedInDevices::class)->signOutOthers($user, session()->getId())['members'];
        } catch (Throwable $e) {
            if (ConcurrencyError::is($e)) {
                throw $e;
            }
            report($e);

            return 0;
        }
    }

    /** One sign-out on record, on the website the member is on: how many, never which devices. */
    private function recordSignOut(User $user, int $count): void
    {
        Audit::record('auth.session.signed_out', [
            'actor_id' => $user->getKey(),
            'actor_label' => $user->email,
            'meta' => ['count' => $count],
        ]);
    }

    private function failed(Throwable $e): void
    {
        if (ConcurrencyError::is($e)) {
            throw $e;
        }
        report($e);
        $this->notify(__('admin.account.ses_failed'), 'error');
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }

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
        // A setup left open (the member walked away) is dropped, so a session picked up later
        // cannot finish it and read the recovery codes without the password.
        if (now()->getTimestamp() - Cast::int(session(self::TFA_SESSION_AT)) > self::TFA_PENDING_SECONDS) {
            session()->forget([self::TFA_SESSION, self::TFA_SESSION_AT]);

            return null;
        }

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /** The form fields and their "saved" twins from what is stored. */
    private function fillProfile(User $user): void
    {
        $this->profileName = $this->savedName = $user->name;
        $this->profileTitle = $this->savedTitle = (string) $user->job_title;
        $this->profilePhone = $this->savedPhone = $user->phone === null ? '' : ContactPhone::display($user->phone, self::PHONE_HOME);
    }

    /**
     * A name or title as stored: any run of spaces, tabs or line breaks (Unicode ones too) becomes one
     * space, invisible format and control characters (zero-width spaces, a right-to-left override
     * that would show the text reversed to colleagues) are dropped, and the ends are trimmed.
     * Invalid UTF-8 comes back as typed, for the check to refuse.
     */
    private static function plain(string $text): string
    {
        $spaced = preg_replace('/[\s\p{Z}]+/u', ' ', $text);
        $clean = $spaced === null ? null : preg_replace('/[\p{Cc}\p{Cf}]/u', '', $spaced);

        return $clean === null ? $text : trim($clean);
    }

    /** Optional, at most TITLE_MAX characters once cleaned (plain()), plain text only (no markup). */
    private function checkTitle(): bool
    {
        $title = self::plain($this->profileTitle);
        if (! mb_check_encoding($title, 'UTF-8') || preg_match('/[<>]/', $title) === 1) {
            $this->addError('profileTitle', __('admin.account.title_markup'));

            return false;
        }

        $over = mb_strlen($title) - self::TITLE_MAX;
        if ($over > 0) {
            $this->addError('profileTitle', __('admin.account.title_too_long', ['count' => $over]));

            return false;
        }

        return true;
    }

    /**
     * The phone to store: null when left empty, the E.164 form of a Vietnamese number (10 or 11
     * digits from 0, or +84) or of a +country number, false (with the error shown) for anything else.
     */
    private function checkPhone(): string|false|null
    {
        $typed = trim($this->profilePhone);
        if ($typed === '') {
            return null;
        }

        $phone = mb_check_encoding($typed, 'UTF-8') ? ContactPhone::normalize($typed, self::PHONE_HOME) : null;
        if ($phone === null) {
            $this->addError('profilePhone', __('admin.account.phone_bad'));

            return false;
        }

        return $phone;
    }

    private function checkName(): bool
    {
        $name = self::plain($this->profileName);
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

    /** The new address in its stored form (trimmed, lower case), or null with the board's error shown. */
    private function checkNewEmail(User $user): ?string
    {
        $email = Str::lower(trim($this->newEmail));
        if (! mb_check_encoding($email, 'UTF-8')
            || mb_strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email) !== 1) {
            $this->addError('newEmail', __('admin.account.em_new_bad'));

            return null;
        }
        if ($email === Str::lower($user->email)) {
            $this->addError('newEmail', __('admin.account.em_new_same'));

            return null;
        }

        return $email;
    }

    /** A code of the change was refused: say why, on $field (a gone change starts again). */
    private function emailCodeRefused(string $field, string $result, User $user, bool $recovery): void
    {
        $app = $field === 'emailFactorCode' && $this->emailFactor === EmailChange::FACTOR_APP;
        match ($result) {
            EmailChange::WRONG => $this->addError($field, match (true) {
                $app && $recovery => __('admin.account.em_recovery_wrong'),
                $app => __('admin.account.tfa_code_wrong'),
                default => __('admin.account.em_code_wrong', ['email' => $this->emailSentTo]),
            }),
            EmailChange::LOCKED => $this->addError($field, $app
                ? __('admin.account.throttled', ['seconds' => app(EmailChange::class)->retryIn($user)])
                : __('admin.account.em_locked')),
            EmailChange::EXPIRED => $this->addError($field, __('admin.account.em_code_expired')),
            default => $this->emailChangeGone(),
        };
    }

    /** The pending change is gone (expired, another session, the account changed): start again from the address. */
    private function emailChangeGone(): void
    {
        $new = $this->newEmail;
        $this->resetEmailChange();
        $this->newEmail = $new;
        $this->emailStep = 1;
        $this->notify(__('admin.account.em_gone'), 'info');
    }

    private function resetEmailChange(): void
    {
        $this->reset('emailStep', 'emailFactor', 'emailSentTo', 'newEmail', 'emailPassword', 'emailFactorCode', 'emailUseRecovery', 'emailCode');
        $this->resetErrorBag(['newEmail', 'emailPassword', 'emailFactorCode', 'emailCode']);
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
