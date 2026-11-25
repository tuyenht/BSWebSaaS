<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Support\OAuthFlow;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SocialAppCheck;
use App\Modules\Admin\Support\SocialAppVerdict;
use App\Modules\Admin\Support\SocialAuth;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * «Đăng nhập nhanh» (Google, Facebook) in two faces of one screen:
 *
 *  - platform (super_admin, the platform console): the shared app every website uses, one per
 *    provider (platform_settings group 'oauth_social'). «Kiểm tra & lưu» asks the provider about
 *    the keys first: refused keys are not saved; keys it could not ask about are saved and left for
 *    the six-hourly check. The master switch goes through the save bar; disconnecting, after its
 *    question, at once. Every write holds the row's lock (PlatformSettings::change()), and new keys
 *    clear the last check's verdict and refusal count.
 *  - site (settings.manage): the website's own switch per provider (site_settings group
 *    'oauth_social'), on wherever the platform opened the provider, through the save bar; and,
 *    folded away, its own app instead of the shared one («Dùng khóa riêng» / «Về ứng dụng chung»,
 *    at once). A save writes `on` and `own` explicitly.
 *
 * Secrets are write-only: typed in the browser, sent as an action's argument, stored encrypted
 * (SecretCipher), and never put in the page's state or HTML — only whether one is saved.
 */
final class SocialSettings extends Component
{
    use TracksSavedState;

    /** @var list<string> */
    public const array PROVIDERS = ['google', 'facebook'];

    private const string GROUP = 'oauth_social';

    /** Which face: 'site' or 'platform' (set by the route; the browser cannot change it). */
    #[Locked]
    public string $scope = 'site';

    /** @var array<string, bool> the switch per provider as the save bar holds it (master switch on the platform face) */
    #[Locked]
    public array $on = [];

    /**
     * What each provider's row shows, read from the saved settings. Platform: client_id, connected
     * (id and secret saved), status ('none'|'ok'|'unchecked'|'error'), users. Site: opened (the
     * shared app is ready), own (the site chose its own app), own_ready, client_id, secret_set.
     *
     * @var array<string, array<string, mixed>>
     */
    #[Locked]
    public array $apps = [];

    /** The question dialog is open (closed from the browser too: Hủy, Escape, the backdrop). */
    public bool $askOpen = false;

    /** 'off' (turn a provider off) or 'disconnect' (the platform drops a provider's app). */
    #[Locked]
    public string $askKind = '';

    #[Locked]
    public string $askProvider = '';

    #[Locked]
    public string $toast = '';

    #[Locked]
    public string $toastTone = 'success';

    #[Locked]
    public int $toastSeq = 0;

    public function boot(): void
    {
        // Defense-in-depth: the livewire/update endpoint bypasses route middleware, so re-check on
        // every request (mount + every action); each action checks its face again (guard()).
        abort_unless(Gate::allows('settings.manage'), 403);
        if ($this->scope === 'platform') {
            abort_unless(self::superAdmin(), 403);
        }
        // Never on someone's behalf: staff signed in for support cannot change this.
        app(SupportSession::class)->forbid();
    }

    public function mount(string $scope = 'site'): void
    {
        $this->scope = $scope === 'platform' ? 'platform' : 'site';
        $this->guard();
        $this->load();
    }

    /** The switch: on at once (until saved); off asks first. */
    public function toggle(string $provider): void
    {
        $this->guard();
        if (! self::offered($provider) || ! $this->switchable($provider)) {
            return;
        }
        if ($this->on[$provider] ?? false) {
            $this->ask('off', $provider);

            return;
        }
        $this->on[$provider] = true;
    }

    /** The platform's «Ngắt kết nối»: asks first. */
    public function askDisconnect(string $provider): void
    {
        $this->guard('platform');
        if (self::offered($provider) && (bool) ($this->apps[$provider]['connected'] ?? false)) {
            $this->ask('disconnect', $provider);
        }
    }

    /** The question's change button. */
    public function confirmAsk(): void
    {
        $this->guard();
        $kind = $this->askKind;
        $provider = $this->askProvider;
        $this->cancelAsk();
        if (! self::offered($provider)) {
            return;
        }
        if ($kind === 'off') {
            $this->on[$provider] = false;
        } elseif ($kind === 'disconnect' && $this->scope === 'platform') {
            $this->disconnect($provider);
        }
    }

    public function cancelAsk(): void
    {
        $this->askOpen = false;
        $this->askKind = '';
        $this->askProvider = '';
    }

    /**
     * The save bar's «Lưu»: the switches that differ from what is saved. False when none did, or
     * when a switch could not be saved (its app was disconnected meanwhile): the page then shows
     * what is saved now and says so.
     */
    public function save(): bool
    {
        $this->guard();
        $chosen = array_values(array_intersect($this->unsavedFields(), self::PROVIDERS));
        if ($chosen === []) {
            return false;
        }

        $missed = false;
        foreach ($chosen as $provider) {
            $on = (bool) ($this->on[$provider] ?? true);
            $written = $this->scope === 'platform'
                ? $this->storeMaster($provider, $on)
                : $this->storeSite($provider, ['on' => $on]);
            $missed = $missed || ! $written;
        }
        $this->load();
        if ($missed) {
            $this->notify(__('admin.sl.switch_meanwhile'), 'error');

            return false;
        }
        $this->notify(__('admin.sl.saved'));

        return true;
    }

    /** Back to the saved switches (the save bar's «Bỏ thay đổi»). */
    public function resetChanges(): void
    {
        $this->guard();
        $this->load();
        $this->resetErrorBag();
    }

    /**
     * The platform's «Kiểm tra & lưu»: the keys are asked about first. A blank secret means the one
     * saved. The answer names the field in error with its message, or ok.
     *
     * @return array{ok: bool, id?: string, secret?: string}
     */
    public function check(string $provider, string $clientId = '', string $secret = ''): array
    {
        $this->guard('platform');
        if (! self::offered($provider)) {
            return ['ok' => false];
        }
        $name = self::providerName($provider);
        $clientId = trim($clientId);
        $secret = trim($secret);

        $platform = app(PlatformSettings::class);
        $stored = Cast::string(($platform->get(self::GROUP, $provider) ?? [])['client_secret'] ?? null);
        $errors = self::formatErrors($provider, $clientId, $secret);
        if ($clientId === '') {
            $errors['id'] = Cast::string(__('admin.sl.need_id', ['label' => __('admin.sl.'.$provider.'.id_label')]));
        }
        if ($secret === '' && $stored === '') {
            $errors['secret'] = Cast::string(__('admin.sl.need_secret', ['label' => __('admin.sl.'.$provider.'.secret_label')]));
        }
        if ($errors !== []) {
            $answer = ['ok' => false];
            foreach ($errors as $field => $message) {
                $answer[$field] = $message;
            }

            return $answer;
        }

        $plain = $secret !== '' ? $secret : SecretCipher::decrypt($stored);
        if ($plain === '') {
            // The saved secret can no longer be read (the settings key changed): a new one is needed.
            return ['ok' => false, 'secret' => __('admin.sl.need_secret', ['label' => __('admin.sl.'.$provider.'.secret_label')])];
        }

        $verdict = app(SocialAppCheck::class)->check($provider, $clientId, $plain);
        if ($verdict === SocialAppVerdict::Invalid) {
            return ['ok' => false, 'secret' => __('admin.sl.refused', ['name' => $name])];
        }

        $encrypted = $secret !== '' ? SecretCipher::encrypt($secret) : $stored;
        $first = false;
        $written = $platform->change(self::GROUP, $provider, function (?array $row) use ($clientId, $secret, $stored, $encrypted, $verdict, &$first): ?array {
            $row ??= [];
            $current = Cast::string($row['client_secret'] ?? null);
            if ($secret === '' && $current !== $stored) {
                return null; // the saved secret was replaced while the provider answered: not the one checked
            }
            $first = Cast::string($row['client_id'] ?? null) === '' || $current === '';
            $same = ! $first
                && $row['client_id'] === $clientId
                && ($secret === '' || SecretCipher::decrypt($current) === $secret);

            $row['client_id'] = $clientId;
            $row['client_secret'] = $encrypted;
            if ($first) {
                $row['enabled'] = true;
            }
            if ($verdict === SocialAppVerdict::Valid) {
                $row = array_merge($row, ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'fails' => 0, 'fails_for' => null]);
            } elseif (! $same) {
                // New keys nobody could check yet: the next check decides.
                $row = array_merge($row, ['status' => null, 'checked_at' => null, 'fails' => 0, 'fails_for' => null]);
            }

            return $row;
        });
        if ($written === null) {
            $this->notify(__('admin.sl.changed_meanwhile'), 'error');

            return ['ok' => false];
        }

        Audit::record('platform.oauth_social.keys', [
            'site_id' => null,
            'actor_id' => auth()->id(),
            'meta' => ['provider' => $provider, 'checked' => $verdict === SocialAppVerdict::Valid],
        ]);
        $this->apps[$provider] = $this->platformApp($provider);
        if ($first) {
            $this->settle($provider, true);
        }
        $this->notify($verdict === SocialAppVerdict::Valid
            ? __('admin.sl.connected_toast', ['name' => $name])
            : __('admin.sl.saved_unchecked', ['name' => $name]), $verdict === SocialAppVerdict::Valid ? 'success' : 'info');

        return ['ok' => true];
    }

    /**
     * «Kiểm tra lại» on a provider the check found broken: asks again with the saved keys.
     * 'ok', 'refused' (the keys are still refused: new ones are needed) or 'unknown'.
     */
    public function recheck(string $provider): string
    {
        $this->guard('platform');
        if (! self::offered($provider)) {
            return 'unknown';
        }
        $name = self::providerName($provider);
        $platform = app(PlatformSettings::class);
        $row = $platform->get(self::GROUP, $provider) ?? [];
        $clientId = Cast::string($row['client_id'] ?? null);
        $stored = Cast::string($row['client_secret'] ?? null);
        $plain = $stored !== '' ? SecretCipher::decrypt($stored) : '';
        $verdict = $clientId !== '' && $plain !== ''
            ? app(SocialAppCheck::class)->check($provider, $clientId, $plain)
            : SocialAppVerdict::Invalid;

        if ($verdict === SocialAppVerdict::Unknown) {
            $this->notify(__('admin.sl.recheck_unknown', ['name' => $name]), 'info');

            return 'unknown';
        }
        if ($verdict === SocialAppVerdict::Invalid) {
            $this->notify(__('admin.sl.still_refused', ['name' => $name]), 'error');

            return 'refused';
        }

        $old = null;
        $written = $platform->change(self::GROUP, $provider, function (?array $row) use ($clientId, $stored, &$old): ?array {
            if ($row === null || ($row['client_id'] ?? null) !== $clientId || ($row['client_secret'] ?? null) !== $stored) {
                return null;
            }
            $old = Cast::stringOrNull($row['status'] ?? null);

            return array_merge($row, ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'fails' => 0, 'fails_for' => null]);
        });
        if ($written === null) {
            $this->notify(__('admin.sl.changed_meanwhile'), 'error');

            return 'unknown';
        }
        if ($old !== 'ok') {
            Audit::record('platform.oauth_social.status', [
                'site_id' => null,
                'actor_id' => auth()->id(),
                'meta' => ['provider' => $provider, 'from' => $old, 'to' => 'ok'],
            ]);
        }
        $this->apps[$provider] = $this->platformApp($provider);
        $this->notify(__('admin.sl.connected_toast', ['name' => $name]));

        return 'ok';
    }

    /**
     * The website's «Dùng khóa riêng»: its own app instead of the shared one, at once. A blank
     * secret keeps the one saved. False (with the reason in the toast) when the keys are missing
     * or not in the provider's shape.
     */
    public function useOwn(string $provider, string $clientId = '', string $secret = ''): bool
    {
        $this->guard('site');
        if (! self::offered($provider)) {
            return false;
        }
        $name = self::providerName($provider);
        $clientId = trim($clientId);
        $secret = trim($secret);
        if ($clientId === '' || ($secret === '' && ! (bool) ($this->apps[$provider]['secret_set'] ?? false))) {
            $this->notify(__('admin.sl.need_both', ['name' => $name]), 'error');

            return false;
        }
        $errors = self::formatErrors($provider, $clientId, $secret);
        if ($errors !== []) {
            $this->notify((string) reset($errors), 'error');

            return false;
        }

        $values = ['own' => true, 'client_id' => $clientId];
        if ($secret !== '') {
            $values['client_secret'] = SecretCipher::encrypt($secret);
        }
        // The form is offered only where the platform opened the provider or the website already
        // uses its own app: the same rule here, on the saved row.
        $opened = app(SocialAuth::class)->sharedReady($provider);
        $written = $this->storeSite($provider, $values, static fn (?array $row): bool => $opened || SocialAuth::choosesOwn($row));
        $this->apps[$provider] = $this->siteApp($provider);
        if (! $written) {
            $this->notify(__('admin.sl.own_closed', ['name' => $name]), 'error');

            return false;
        }
        $this->notify(__('admin.sl.own_toast', ['name' => $name]));

        return true;
    }

    /** «Về ứng dụng chung»: back to the platform's app, its own keys kept for later. */
    public function useShared(string $provider): void
    {
        $this->guard('site');
        if (! self::offered($provider)) {
            return;
        }
        // Offered only while the website uses its own app.
        $written = $this->storeSite($provider, ['own' => false], static fn (?array $row): bool => SocialAuth::choosesOwn($row));
        $this->apps[$provider] = $this->siteApp($provider);
        if ($written) {
            $this->notify(__('admin.sl.shared_toast'));
        }
    }

    /** The browser copied `$count` values for the provider's console (0: it could not). */
    public function copied(string $provider, int $count): void
    {
        $this->guard('platform');
        if (! self::offered($provider)) {
            return;
        }
        $this->notify($count > 0
            ? __('admin.sl.copied_all', ['count' => $count, 'console' => __('admin.sl.'.$provider.'.console')])
            : __('admin.sl.copy_failed'), $count > 0 ? 'success' : 'error');
    }

    public function dismissToast(?int $seq = null): void
    {
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toast = '';
        }
    }

    /**
     * The board's checks of a key's shape, by provider (an empty value is not checked here).
     *
     * @return array<'id'|'secret', string>
     */
    public static function formatErrors(string $provider, string $clientId, string $secret): array
    {
        $out = [];
        if ($provider === 'google') {
            if ($clientId !== '' && (strlen($clientId) > 255 || preg_match('/\.apps\.googleusercontent\.com\z/i', $clientId) !== 1)) {
                $out['id'] = __('admin.sl.google.id_invalid');
            }
            if ($secret !== '' && (strlen($secret) > 255 || ! str_starts_with($secret, 'GOCSPX-'))) {
                $out['secret'] = __('admin.sl.google.secret_invalid');
            }
        } else {
            if ($clientId !== '' && preg_match('/\A\d{13,18}\z/', $clientId) !== 1) {
                $out['id'] = __('admin.sl.facebook.id_invalid');
            }
            if ($secret !== '' && preg_match('/\A[a-f0-9]{32}\z/i', $secret) !== 1) {
                $out['secret'] = __('admin.sl.facebook.secret_invalid');
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function trackedState(): array
    {
        return $this->on;
    }

    /** Who may use this face: the platform face is super_admin only, the site face needs settings.manage. */
    private function guard(?string $face = null): void
    {
        abort_unless(Gate::allows('settings.manage'), 403);
        if ($this->scope === 'platform') {
            abort_unless(self::superAdmin(), 403);
        }
        abort_if($face !== null && $face !== $this->scope, 403);
        app(SupportSession::class)->forbid();
    }

    /**
     * The switch shows only where it can work: the platform's on a connected app, a website's on an
     * app the platform opened or on its own complete keys (otherwise the row shows «Chưa mở»).
     */
    private function switchable(string $provider): bool
    {
        $app = $this->apps[$provider] ?? [];

        return $this->scope === 'platform'
            ? (bool) ($app['connected'] ?? false)
            : (bool) ($app['opened'] ?? false) || (bool) ($app['own_ready'] ?? false);
    }

    private function ask(string $kind, string $provider): void
    {
        $this->askKind = $kind;
        $this->askProvider = $provider;
        $this->askOpen = true;
    }

    private function load(): void
    {
        // The linked accounts of every provider in one query (live: no counter yet).
        $users = $this->scope === 'platform' ? app(SocialAuth::class)->usersCounts(self::PROVIDERS) : [];
        foreach (self::PROVIDERS as $provider) {
            if ($this->scope === 'platform') {
                $this->apps[$provider] = $this->platformApp($provider, $users[$provider] ?? null);
                $this->on[$provider] = (bool) $this->apps[$provider]['enabled'];
            } else {
                $this->apps[$provider] = $this->siteApp($provider);
                $this->on[$provider] = (bool) $this->apps[$provider]['on'];
            }
        }
        $this->rememberSaved();
    }

    /**
     * @param  int|null  $users  the provider's linked accounts when already counted
     * @return array{client_id: string, connected: bool, status: string, users: int, enabled: bool}
     */
    private function platformApp(string $provider, ?int $users = null): array
    {
        $row = app(PlatformSettings::class)->get(self::GROUP, $provider) ?? [];
        $clientId = Cast::string($row['client_id'] ?? null);
        $connected = $clientId !== '' && Cast::string($row['client_secret'] ?? null) !== '';
        $status = match (true) {
            ! $connected => 'none',
            ($row['status'] ?? null) === 'error' => 'error',
            ($row['status'] ?? null) === 'ok' => 'ok',
            default => 'unchecked',
        };

        return [
            'client_id' => $clientId,
            'connected' => $connected,
            'status' => $status,
            'users' => $connected ? ($users ?? app(SocialAuth::class)->usersCount($provider)) : 0,
            'enabled' => ($row['enabled'] ?? true) !== false,
        ];
    }

    /** @return array{on: bool, opened: bool, own: bool, own_ready: bool, client_id: string, secret_set: bool} */
    private function siteApp(string $provider): array
    {
        $choice = app(SocialAuth::class)->siteChoice($provider);
        $row = app(SettingsRepository::class)->get(self::GROUP, $provider) ?? [];

        return [
            'on' => $choice['on'],
            'opened' => $choice['shared_ready'],
            'own' => $choice['own'],
            'own_ready' => $choice['own_ready'],
            'client_id' => Cast::string($row['client_id'] ?? null),
            'secret_set' => Cast::string($row['client_secret'] ?? null) !== '',
        ];
    }

    /** The platform's master switch, on a provider that has an app (none: nothing is written, false). */
    private function storeMaster(string $provider, bool $on): bool
    {
        $from = null;
        $written = app(PlatformSettings::class)->change(self::GROUP, $provider, function (?array $row) use ($on, &$from): ?array {
            if ($row === null || Cast::string($row['client_id'] ?? null) === '' || Cast::string($row['client_secret'] ?? null) === '') {
                return null;
            }
            $from = ($row['enabled'] ?? true) !== false;
            $row['enabled'] = $on;

            return $row;
        });
        if ($written !== null && $from !== $on) {
            Audit::record('platform.oauth_social.switch', [
                'site_id' => null,
                'actor_id' => auth()->id(),
                'meta' => ['provider' => $provider, 'on' => $on],
            ]);
        }

        return $written !== null;
    }

    /**
     * Writes the website's row with `on` and `own` always explicit (an old row without them reads
     * as SocialAuth reads it), the given values over what is saved — all on the row as it is now,
     * under its lock (SettingsRepository::change()). `$allowed` judges that row; false writes
     * nothing. True when the row was written.
     *
     * @param  array<string, mixed>  $values
     * @param  (callable(array<string, mixed>|null): bool)|null  $allowed
     */
    private function storeSite(string $provider, array $values, ?callable $allowed = null): bool
    {
        return app(SettingsRepository::class)->change(self::GROUP, $provider, static function (?array $row) use ($values, $allowed): ?array {
            if ($allowed !== null && ! $allowed($row)) {
                return null;
            }

            return array_merge($row ?? [], ['on' => SocialAuth::switchedOn($row), 'own' => SocialAuth::choosesOwn($row)], $values);
        }) !== null;
    }

    private function disconnect(string $provider): void
    {
        $written = app(PlatformSettings::class)->change(self::GROUP, $provider, static fn (?array $row): ?array => $row === null ? null : []);
        if ($written !== null) {
            Audit::record('platform.oauth_social.disconnected', [
                'site_id' => null,
                'actor_id' => auth()->id(),
                'meta' => ['provider' => $provider],
            ]);
        }
        $this->apps[$provider] = $this->platformApp($provider);
        $this->settle($provider, true);
        $this->notify(__('admin.sl.disconnected_toast', ['name' => self::providerName($provider)]));
    }

    /** One switch is now saved as `$on`, the others keep what the member changed and not saved. */
    private function settle(string $provider, bool $on): void
    {
        $this->on[$provider] = $on;
        $this->savedState = array_merge($this->savedState, self::fingerprints([$provider => $on]));
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }

    private static function offered(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true);
    }

    private static function providerName(string $provider): string
    {
        return __('admin.sl.'.$provider.'.name');
    }

    private static function superAdmin(): bool
    {
        $user = auth()->user();

        return $user !== null && app(PlatformAccess::class)->isSuperAdmin($user);
    }

    /**
     * What the platform face offers to copy into the provider's console, all derived from the one
     * sign-in return address; the policy pages only when they are configured.
     *
     * @return list<array{key: string, label: string, value: string, where: string, why: string}>
     */
    private static function copyRows(string $provider): array
    {
        $callback = OAuthFlow::callbackUrl();
        $parts = parse_url($callback);
        $host = Cast::string($parts['host'] ?? null);
        $base = Cast::string($parts['scheme'] ?? null, 'https').'://'.$host.(isset($parts['port']) ? ':'.Cast::int($parts['port']) : '');
        $values = $provider === 'google'
            ? ['redirect' => $callback, 'origin' => $base]
            : ['redirect' => $callback, 'domain' => $host, 'site' => $base, 'deletion' => $base.route('oauth.facebook.deletion', [], false)];
        $values['privacy'] = Cast::string(config('oauth.legal.privacy_url'));
        $values['terms'] = Cast::string(config('oauth.legal.terms_url'));

        $rows = [];
        foreach ($values as $key => $value) {
            if ($value === '') {
                continue;
            }
            $prefix = 'admin.sl.'.$provider.'.copy.'.$key;
            $rows[] = ['key' => $key, 'label' => __($prefix), 'value' => $value, 'where' => __($prefix.'_where'), 'why' => __($prefix.'_why')];
        }

        return $rows;
    }

    public function render(): View
    {
        $platform = $this->scope === 'platform';
        $view = view('admin::livewire.social-settings', [
            'changes' => $this->unsavedCount(),
            'copyRows' => $platform ? array_combine(self::PROVIDERS, array_map(self::copyRows(...), self::PROVIDERS)) : [],
            'consoles' => array_combine(self::PROVIDERS, array_map(static fn (string $p): string => Cast::string(config('oauth.providers.'.$p.'.console_url')), self::PROVIDERS)),
        ]);
        $view->layout($platform ? 'admin::layouts.platform' : 'admin::layouts.app');
        $view->title($platform ? __('admin.system_social') : __('admin.social_login'));

        return $view;
    }
}
