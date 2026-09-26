<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Support\OAuthFlow;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SocialAuth;
use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
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
final readonly class OAuthController
{
    private const int STATE_TTL = 600;

    private const int LOGIN_TTL = 120;

    private const string LOGIN_NONCE_COOKIE = 'oauth_login_nonce';

    public function __construct(
        private OAuthFlow $flow,
        private SocialAuth $social,
        private SettingsRepository $tenant,
        private PlatformSettings $platform,
        private PlatformAccess $platformAccess,
        private Tenancy $tenancy,
    ) {}

    public function start(Request $request, string $provider): RedirectResponse
    {
        $purpose = match ($request->query('purpose')) {
            'email' => 'email',
            'connect' => 'connect',
            default => 'login',
        };
        $cfg = config("oauth.providers.$provider");

        if (! is_array($cfg) || ! in_array($purpose, Cast::arr($cfg['supports'] ?? null), true)) {
            return $this->failLocal($purpose, __('admin.oauth_provider_not_configured'));
        }

        // 'email' (sender connect) and 'connect' (social link) act on the current account.
        if (in_array($purpose, ['email', 'connect'], true) && ! Auth::check()) {
            return redirect()->route('admin.login');
        }

        // start runs on the tenant host (tenant context resolved) — its OAuth app lives there.
        [$clientId, $clientSecret] = $this->creds($provider, $purpose);
        if ($clientId === null || $clientSecret === null) {
            return $this->failLocal($purpose, __('admin.oauth_provider_not_configured'));
        }

        $state = Str::random(40);
        $verifier = Str::random(64);

        // Bind this flow to the initiating browser. A random nonce is set as an HttpOnly cookie on
        // THIS host — finish() runs on the same origin host, so the cookie is readable there — and
        // only its hash is stored server-side. finish() refuses a login token whose nonce cookie is
        // missing or wrong, closing the login-CSRF / session-fixation window in which a captured
        // handoff token could otherwise complete into any browser.
        $nonce = Str::random(40);

        Cache::put('oauth-state:'.$state, [
            'origin' => $request->getSchemeAndHttpHost(),
            'provider' => $provider,
            'purpose' => $purpose,
            'verifier' => $verifier,
            'user_id' => Auth::id(),
            'nonce' => hash('sha256', $nonce),
        ], self::STATE_TTL);

        // Cookie must outlive a login token minted at the very end of the state window (state TTL)
        // and living LOGIN_TTL beyond it — otherwise a slow consent screen leaves a valid token with
        // no cookie to match. +1 min of headroom.
        $cookieMinutes = intdiv(self::STATE_TTL + self::LOGIN_TTL, 60) + 1;

        return redirect()->away(
            $this->flow->authorizeUrl($provider, $purpose, $clientId, OAuthFlow::callbackUrl(), $state, $this->pkceChallenge($verifier)),
        )->withCookie(Cookie::make(self::LOGIN_NONCE_COOKIE, $nonce, $cookieMinutes, null, null, null, true, false, 'lax'));
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
        $userId = Cast::int($data['user_id'] ?? null);
        $nonceHash = Cast::string($data['nonce'] ?? null);
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
            fn (): RedirectResponse => $this->complete($request, $origin, $provider, $purpose, $verifier, $userId, $nonceHash),
        );
    }

    public function finish(Request $request): RedirectResponse
    {
        $token = $request->query('token');
        $data = is_string($token) ? Cache::pull('oauth-login:'.$token) : null;
        $cookieNonce = $request->cookie(self::LOGIN_NONCE_COOKIE);

        // Drop the one-shot nonce cookie whatever happens next.
        $forget = Cookie::forget(self::LOGIN_NONCE_COOKIE);

        // The login token must be presented by the SAME browser that began the flow: its payload
        // carries the hash of the nonce set as an HttpOnly cookie at start(). A token replayed or
        // forged into a different browser has no matching cookie and is refused (fail closed).
        if (! is_array($data)
            || ! is_string($cookieNonce) || $cookieNonce === ''
            || ! hash_equals(Cast::string($data['nonce'] ?? null), hash('sha256', $cookieNonce))) {
            return redirect()->route('admin.login')->withErrors(['email' => __('admin.oauth_failed')])->withCookie($forget);
        }

        $user = User::find(Cast::int($data['uid'] ?? null));
        if (! $user instanceof User) {
            return redirect()->route('admin.login')->withErrors(['email' => __('admin.oauth_failed')])->withCookie($forget);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'))->withCookie($forget);
    }

    private function complete(Request $request, string $origin, string $provider, string $purpose, string $verifier, int $userId, string $nonceHash): RedirectResponse
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

        return match ($purpose) {
            'email' => $this->finishEmail($origin, $provider, $tokens, $info),
            'connect' => $this->finishConnect($origin, $provider, $info, $userId),
            default => $this->finishLogin($origin, $provider, $info, $nonceHash),
        };
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function finishLogin(string $origin, string $provider, array $info, string $nonceHash): RedirectResponse
    {
        $sub = Cast::string($info[$this->subjectField($provider)] ?? null);

        // Fast path: a previously-established (provider, sub) link. The link was created by an
        // AUTHENTICATED connect (or an earlier verified-email login), so it is itself the proof
        // of ownership — no email_verified needed, which is what lets Facebook/MS/Yahoo work.
        if ($sub !== '') {
            $linked = SocialIdentity::query()->where('provider', $provider)->where('sub', $sub)->first();
            if ($linked !== null) {
                $user = User::find($linked->user_id);
                if ($user instanceof User && $this->memberOfOrigin($user)) {
                    return $this->bounceLogin($origin, $user, $nonceHash);
                }
            }
        }

        // Email path: the provider must ASSERT a verified email (default-deny if absent) — closes
        // takeover via a provider that doesn't verify ownership (e.g. Facebook's /me email).
        $field = Cast::string(config("oauth.providers.$provider.email_field"), 'email');
        $email = $info[$field] ?? null;
        if (! is_string($email) || $email === '' || ($info['email_verified'] ?? false) !== true) {
            return $this->failTo($origin, 'login', __('admin.oauth_failed'));
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            return $this->failTo($origin, 'login', __('admin.oauth_login_no_account'));
        }

        // We are inside the origin site's tenant context — membership = a role there.
        if (! $this->memberOfOrigin($user)) {
            return $this->failTo($origin, 'login', __('admin.oauth_login_not_member'));
        }

        // Remember this verified identity so next time the fast path matches by sub.
        if ($sub !== '') {
            SocialIdentity::query()->firstOrCreate(
                ['provider' => $provider, 'sub' => $sub],
                ['user_id' => $user->getKey(), 'email' => $email],
            );
        }

        return $this->bounceLogin($origin, $user, $nonceHash);
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
        $cfg['from_address'] ??= $connectedEmail;
        $this->tenant->put('email', 'config', $cfg);

        return redirect()->away($this->originPath($origin, 'settings/email').'?oauth=connected');
    }

    /**
     * Link the authenticated member's account to this provider identity (sub). The member was
     * authenticated on the ORIGIN host when they started connect; their id rode along in the
     * single-use state (the callback host has no session), so it is trustworthy here.
     *
     * @param  array<string, mixed>  $info
     */
    private function finishConnect(string $origin, string $provider, array $info, int $userId): RedirectResponse
    {
        $user = User::find($userId);
        $sub = Cast::string($info[$this->subjectField($provider)] ?? null);

        if (! $user instanceof User || $sub === '') {
            return $this->failTo($origin, 'connect', __('admin.oauth_failed'));
        }

        // A provider identity belongs to only ONE account — fail with a clear message rather
        // than letting the (provider, sub) unique index throw.
        $owner = SocialIdentity::query()->where('provider', $provider)->where('sub', $sub)->first();
        if ($owner !== null && $owner->user_id !== $user->getKey()) {
            return $this->failTo($origin, 'connect', __('admin.social_already_linked'));
        }

        SocialIdentity::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'provider' => $provider],
            ['sub' => $sub, 'email' => Cast::stringOrNull($info['email'] ?? null)],
        );

        return redirect()->away($this->originPath($origin, 'settings/security').'?oauth=connected');
    }

    private function subjectField(string $provider): string
    {
        return Cast::string(config("oauth.providers.$provider.subject_field"), 'sub');
    }

    /** Inside the origin site's tenant context — membership = a role there (or a platform op). */
    private function memberOfOrigin(User $user): bool
    {
        return $this->platformAccess->isSuperAdmin($user) || $user->roles()->exists();
    }

    /**
     * The session lives on the origin host — bounce there with a single-use login token. The token
     * payload carries the nonce hash so finish() can verify the same browser that began the flow.
     */
    private function bounceLogin(string $origin, User $user, string $nonceHash): RedirectResponse
    {
        $token = Str::random(48);
        Cache::put('oauth-login:'.$token, [
            'uid' => $user->getAuthIdentifier(),
            'nonce' => $nonceHash,
        ], self::LOGIN_TTL);

        return redirect()->away($this->originPath($origin, 'oauth/finish').'?token='.$token);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function creds(string $provider, string $purpose): array
    {
        if ($purpose === 'login' || $purpose === 'connect') {
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
        $path = match ($purpose) {
            'email' => 'settings/email',
            'connect' => 'settings/security',
            default => 'login',
        };

        return redirect()->away($this->originPath($origin, $path).'?oauth_error='.urlencode($message));
    }

    private function failLocal(string $purpose, string $message): RedirectResponse
    {
        $route = match (true) {
            $purpose === 'email' && Auth::check() => 'admin.settings.email',
            $purpose === 'connect' && Auth::check() => 'admin.settings.security',
            default => 'admin.login',
        };

        return redirect()->route($route)->withErrors(['oauth' => $message]);
    }
}
