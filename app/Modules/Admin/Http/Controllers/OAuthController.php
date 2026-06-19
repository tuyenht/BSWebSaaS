<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Support\Cast;
use App\Modules\Admin\Support\OAuthFlow;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SocialAuth;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Centralized OAuth2 — social login + email-sender connect. ONE canonical redirect URI
 * (OAuthFlow::callbackUrl(), on the project's main domain) is registered with each provider
 * and shared by EVERY tenant — so an operator registers it once, not per-site.
 *
 * Flow: start() runs on the tenant host, stashes the ORIGIN host in a single-use, 10-min
 * server-side `state` (cache → cross-host safe), and sends the canonical redirect URI. The
 * central callback() resolves the origin → its site → enters that tenant's context to read
 * its OAuth app + exchange the code, then:
 *   - email   → stores the refresh token in that tenant's email config;
 *   - login   → (session lives on the origin host) bounces back to {origin}/oauth/finish
 *               with a single-use token; finish() logs the user in there.
 *
 * Security: state + login token are single-use (Cache::pull); social login NEVER auto-
 * provisions — the provider-verified email must already be a member of the origin site.
 */
final class OAuthController
{
    private const STATE_TTL = 600;

    private const LOGIN_TTL = 120;

    public function __construct(
        private readonly OAuthFlow $flow,
        private readonly SocialAuth $social,
        private readonly SettingsRepository $tenant,
        private readonly PlatformSettings $platform,
        private readonly PlatformAccess $platformAccess,
        private readonly Tenancy $tenancy,
    ) {}

    public function start(Request $request, string $provider): RedirectResponse
    {
        $purpose = $request->query('purpose') === 'email' ? 'email' : 'login';
        $cfg = config("oauth.providers.$provider");

        if (! is_array($cfg) || ! in_array($purpose, Cast::arr($cfg['supports'] ?? null), true)) {
            return $this->failLocal($purpose, __('admin.oauth_provider_not_configured'));
        }

        if ($purpose === 'email' && ! Auth::check()) {
            return redirect()->route('admin.login');
        }

        // start runs on the tenant host (tenant context resolved) — its OAuth app lives there.
        [$clientId, $clientSecret] = $this->creds($provider, $purpose);
        if ($clientId === null || $clientSecret === null) {
            return $this->failLocal($purpose, __('admin.oauth_provider_not_configured'));
        }

        $state = Str::random(40);
        $verifier = Str::random(64);
        Cache::put('oauth-state:'.$state, [
            'origin' => $request->getSchemeAndHttpHost(),
            'provider' => $provider,
            'purpose' => $purpose,
            'verifier' => $verifier,
        ], self::STATE_TTL);

        return redirect()->away(
            $this->flow->authorizeUrl($provider, $purpose, $clientId, OAuthFlow::callbackUrl(), $state, $this->pkceChallenge($verifier)),
        );
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        $data = is_string($state) ? Cache::pull('oauth-state:'.$state) : null;

        if (! is_array($data)) {
            return redirect()->route('admin.login')->withErrors(['oauth' => __('admin.oauth_state_mismatch')]);
        }

        $origin = Cast::string($data['origin'] ?? null);
        $provider = Cast::string($data['provider'] ?? null);
        $purpose = Cast::string($data['purpose'] ?? null, 'login');
        $verifier = Cast::string($data['verifier'] ?? null);
        $host = (string) (parse_url($origin, PHP_URL_HOST) ?: '');

        if ($request->query('error') !== null || ! is_string($request->query('code'))) {
            return $this->failTo($origin, $purpose, __('admin.oauth_failed'));
        }

        $domain = Domain::query()->where('hostname', $host)->first();
        if ($domain === null) {
            return $this->failTo($origin, $purpose, __('admin.oauth_failed'));
        }

        // Re-enter the originating tenant's context to read its app + store/verify.
        return $this->tenancy->enter(
            $domain->site_id,
            fn (): RedirectResponse => $this->complete($request, $origin, $provider, $purpose, $verifier),
        );
    }

    public function finish(Request $request): RedirectResponse
    {
        $token = $request->query('token');
        $userId = is_string($token) ? Cache::pull('oauth-login:'.$token) : null;
        $user = $userId !== null ? User::find($userId) : null;

        if (! $user instanceof User) {
            return redirect()->route('admin.login')->withErrors(['email' => __('admin.oauth_failed')]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    private function complete(Request $request, string $origin, string $provider, string $purpose, string $verifier): RedirectResponse
    {
        [$clientId, $clientSecret] = $this->creds($provider, $purpose);
        if ($clientId === null || $clientSecret === null) {
            return $this->failTo($origin, $purpose, __('admin.oauth_provider_not_configured'));
        }

        try {
            $tokens = $this->flow->exchangeCode($provider, (string) $request->query('code'), $clientId, $clientSecret, OAuthFlow::callbackUrl(), $verifier);
            $info = $this->flow->userInfo($provider, Cast::string($tokens['access_token'] ?? null));
        } catch (Throwable $e) {
            report($e);

            return $this->failTo($origin, $purpose, __('admin.oauth_failed'));
        }

        return $purpose === 'email'
            ? $this->finishEmail($origin, $provider, $tokens, $info)
            : $this->finishLogin($origin, $provider, $info);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function finishLogin(string $origin, string $provider, array $info): RedirectResponse
    {
        $field = Cast::string(config("oauth.providers.$provider.email_field"), 'email');
        $email = $info[$field] ?? null;

        // Require the provider to ASSERT a verified email (default-deny if absent) — closes
        // takeover via a provider that doesn't verify ownership (e.g. Facebook's /me email).
        if (! is_string($email) || $email === '' || ($info['email_verified'] ?? false) !== true) {
            return $this->failTo($origin, 'login', __('admin.oauth_failed'));
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            return $this->failTo($origin, 'login', __('admin.oauth_login_no_account'));
        }

        // We are inside the origin site's tenant context — membership = a role there.
        if (! $this->platformAccess->isSuperAdmin($user) && ! $user->roles()->exists()) {
            return $this->failTo($origin, 'login', __('admin.oauth_login_not_member'));
        }

        // The session lives on the origin host, not here — bounce with a single-use token.
        $token = Str::random(48);
        Cache::put('oauth-login:'.$token, $user->getAuthIdentifier(), self::LOGIN_TTL);

        return redirect()->away($this->originPath($origin, 'oauth/finish').'?token='.$token);
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<string, mixed>  $info
     */
    private function finishEmail(string $origin, string $provider, array $tokens, array $info): RedirectResponse
    {
        $refresh = $tokens['refresh_token'] ?? null;
        if (! is_string($refresh) || $refresh === '') {
            return redirect()->away($this->originPath($origin, 'settings/email').'?oauth_error='.urlencode(__('admin.oauth_no_refresh_token')));
        }

        $field = Cast::string(config("oauth.providers.$provider.email_field"), 'email');
        $connectedEmail = is_string($info[$field] ?? null) ? $info[$field] : null;

        $cfg = $this->tenant->get('email') ?? [];
        $cfg['method'] = 'oauth2';
        $cfg['oauth'] = array_merge(Cast::arr($cfg['oauth'] ?? null), [
            'provider' => $provider,
            'refresh_token' => SecretCipher::encrypt($refresh),
            'email' => $connectedEmail,
        ]);
        $cfg['from_address'] = $cfg['from_address'] ?? $connectedEmail;
        $this->tenant->put('email', 'config', $cfg);

        return redirect()->away($this->originPath($origin, 'settings/email').'?oauth=connected');
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function creds(string $provider, string $purpose): array
    {
        if ($purpose === 'login') {
            $cfg = $this->social->config($provider) ?? [];

            return [
                is_string($cfg['client_id'] ?? null) ? $cfg['client_id'] : null,
                is_string($cfg['client_secret'] ?? null) ? SecretCipher::decrypt($cfg['client_secret']) : null,
            ];
        }

        $email = $this->tenant->get('email') ?? $this->platform->get('email') ?? [];
        $oauth = Cast::arr($email['oauth'] ?? null);

        return [
            is_string($oauth['client_id'] ?? null) ? $oauth['client_id'] : null,
            is_string($oauth['client_secret'] ?? null) ? SecretCipher::decrypt($oauth['client_secret']) : null,
        ];
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function originPath(string $origin, string $path): string
    {
        $prefix = trim(Cast::string(config('admin.prefix'), 'admin'), '/');

        return rtrim($origin, '/').'/'.$prefix.'/'.ltrim($path, '/');
    }

    private function failTo(string $origin, string $purpose, string $message): RedirectResponse
    {
        $path = $purpose === 'email' ? 'settings/email' : 'login';

        return redirect()->away($this->originPath($origin, $path).'?oauth_error='.urlencode($message));
    }

    private function failLocal(string $purpose, string $message): RedirectResponse
    {
        $route = $purpose === 'email' && Auth::check() ? 'admin.settings.email' : 'admin.login';

        return redirect()->route($route)->withErrors(['oauth' => $message]);
    }
}
