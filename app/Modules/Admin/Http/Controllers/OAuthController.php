<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Support\OAuthFlow;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SocialAuth;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * OAuth2 redirect endpoints shared by social login and email-sender connect. One pair of
 * routes ({provider}/start + callback); the session-stored `purpose` decides the callback
 * branch. Lives on the guest tenant-context group (login must reach it pre-auth); the
 * 'email' purpose enforces auth in-controller.
 *
 * Security: CSRF-style `state` is generated per attempt and verified on return; social
 * login NEVER auto-provisions — the provider-verified email must already be a member of
 * THIS site (a role on the current team) or a platform operator.
 */
final class OAuthController
{
    public function __construct(
        private readonly OAuthFlow $flow,
        private readonly SocialAuth $social,
        private readonly SettingsRepository $tenant,
        private readonly PlatformSettings $platform,
        private readonly PlatformAccess $platformAccess,
    ) {}

    public function start(Request $request, string $provider): RedirectResponse
    {
        $purpose = $request->query('purpose') === 'email' ? 'email' : 'login';
        $cfg = config("oauth.providers.$provider");

        if (! is_array($cfg) || ! in_array($purpose, $cfg['supports'] ?? [], true)) {
            return $this->fail($purpose, __('admin.oauth_provider_not_configured'));
        }

        if ($purpose === 'email' && ! Auth::check()) {
            return redirect()->route('admin.login');
        }

        [$clientId, $clientSecret] = $this->creds($provider, $purpose);
        if ($clientId === null || $clientSecret === null) {
            return $this->fail($purpose, __('admin.oauth_provider_not_configured'));
        }

        $state = Str::random(40);
        session(['oauth' => ['state' => $state, 'provider' => $provider, 'purpose' => $purpose]]);

        return redirect()->away(
            $this->flow->authorizeUrl($provider, $purpose, $clientId, route('admin.oauth.callback'), $state),
        );
    }

    public function callback(Request $request): RedirectResponse
    {
        $sess = session('oauth');
        session()->forget('oauth');

        if (! is_array($sess) || ! is_string($request->query('state')) || ! hash_equals((string) ($sess['state'] ?? ''), (string) $request->query('state'))) {
            return $this->fail('login', __('admin.oauth_state_mismatch'));
        }

        $provider = (string) $sess['provider'];
        $purpose = (string) $sess['purpose'];

        if ($request->query('error') !== null || ! is_string($request->query('code'))) {
            return $this->fail($purpose, __('admin.oauth_failed'));
        }

        [$clientId, $clientSecret] = $this->creds($provider, $purpose);
        if ($clientId === null || $clientSecret === null) {
            return $this->fail($purpose, __('admin.oauth_provider_not_configured'));
        }

        try {
            $tokens = $this->flow->exchangeCode($provider, (string) $request->query('code'), $clientId, $clientSecret, route('admin.oauth.callback'));
            $info = $this->flow->userInfo($provider, (string) ($tokens['access_token'] ?? ''));
        } catch (Throwable $e) {
            report($e);

            return $this->fail($purpose, __('admin.oauth_failed'));
        }

        return $purpose === 'email'
            ? $this->finishEmail($provider, $tokens, $info)
            : $this->finishLogin($request, $provider, $info);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function finishLogin(Request $request, string $provider, array $info): RedirectResponse
    {
        $field = (string) config("oauth.providers.$provider.email_field", 'email');
        $email = $info[$field] ?? null;

        // Trust only provider-verified emails (Google/MS expose email_verified).
        if (! is_string($email) || $email === '' || ($info['email_verified'] ?? true) === false) {
            return $this->fail('login', __('admin.oauth_failed'));
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            return redirect()->route('admin.login')->withErrors(['email' => __('admin.oauth_login_no_account')]);
        }

        // Membership: a role on the current site (spatie team = site_id) or platform operator.
        if (! $this->platformAccess->isSuperAdmin($user) && ! $user->roles()->exists()) {
            return redirect()->route('admin.login')->withErrors(['email' => __('admin.oauth_login_not_member')]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<string, mixed>  $info
     */
    private function finishEmail(string $provider, array $tokens, array $info): RedirectResponse
    {
        $refresh = $tokens['refresh_token'] ?? null;
        if (! is_string($refresh) || $refresh === '') {
            // No refresh token = we could not send later. Most often the app wasn't granted
            // offline access — surface it instead of silently "connecting".
            return redirect()->route('admin.settings.email')->withErrors(['oauth' => __('admin.oauth_no_refresh_token')]);
        }

        $field = (string) config("oauth.providers.$provider.email_field", 'email');
        $connectedEmail = is_string($info[$field] ?? null) ? $info[$field] : (Auth::user()?->email);

        $cfg = $this->tenant->get('email') ?? [];
        $cfg['method'] = 'oauth2';
        $cfg['oauth'] = array_merge($cfg['oauth'] ?? [], [
            'provider' => $provider,
            'refresh_token' => SecretCipher::encrypt($refresh),
            'email' => $connectedEmail,
        ]);
        $cfg['from_address'] = $cfg['from_address'] ?? $connectedEmail;
        $this->tenant->put('email', 'config', $cfg);

        return redirect()->route('admin.settings.email')->with('status', __('admin.oauth_connected_ok'));
    }

    /**
     * Resolve the OAuth app credentials for a (provider, purpose). Login creds live in the
     * social group; email creds live alongside the email config (tenant → platform).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function creds(string $provider, string $purpose): array
    {
        if ($purpose === 'login') {
            $cfg = $this->social->config($provider) ?? [];

            return [
                is_string($cfg['client_id'] ?? null) ? $cfg['client_id'] : null,
                isset($cfg['client_secret']) ? SecretCipher::decrypt($cfg['client_secret']) : null,
            ];
        }

        $email = $this->tenant->get('email') ?? $this->platform->get('email') ?? [];
        $oauth = $email['oauth'] ?? [];

        return [
            is_string($oauth['client_id'] ?? null) ? $oauth['client_id'] : null,
            isset($oauth['client_secret']) ? SecretCipher::decrypt($oauth['client_secret']) : null,
        ];
    }

    private function fail(string $purpose, string $message): RedirectResponse
    {
        $route = $purpose === 'email' && Auth::check() ? 'admin.settings.email' : 'admin.login';

        return redirect()->route($route)->withErrors(['oauth' => $message]);
    }
}
