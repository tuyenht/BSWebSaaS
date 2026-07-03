<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Provider-agnostic OAuth2 Authorization-Code flow over Laravel's HTTP client — no SDK,
 * so adding/adjusting a provider is a config/oauth.php change, not a dependency. Pure:
 * callers pass the resolved client id/secret (decrypted at the boundary); this never
 * touches storage. Used for social login (resolve email) and email sending (refresh
 * token → XOAUTH2 access token).
 */
final class OAuthFlow
{
    /**
     * @return array<string, mixed>
     */
    public function provider(string $provider): array
    {
        $cfg = config("oauth.providers.$provider");

        if (! is_array($cfg)) {
            throw new RuntimeException("Unknown OAuth provider: $provider");
        }

        /** @var array<string, mixed> $cfg */
        return $cfg;
    }

    /**
     * The ONE canonical redirect URI registered with every provider — on the project's
     * main domain, shared by every tenant + the platform (the callback resolves the origin
     * from state). Config-driven (OAUTH_CALLBACK_URL); defaults to the platform host.
     */
    public static function callbackUrl(): string
    {
        $url = Cast::string(config('admin.oauth_callback_url'));
        if ($url !== '') {
            return rtrim($url, '/');
        }

        $host = Cast::string(config('tenancy.platform_host'));
        if ($host !== '') {
            return 'https://'.$host.'/oauth/callback';
        }

        return route('oauth.callback');
    }

    public function authorizeUrl(string $provider, string $purpose, string $clientId, string $redirectUri, string $state, ?string $codeChallenge = null): string
    {
        $cfg = $this->provider($provider);
        $scopeMap = Cast::arr($cfg['scopes'] ?? null);
        $scopes = Cast::strings($scopeMap[$purpose] ?? $scopeMap['login'] ?? null);

        $params = array_merge([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ], Cast::arr($cfg['authorize_params'] ?? null));

        // PKCE (S256) — binds this authorization request to the token exchange below.
        if ($codeChallenge !== null) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return Cast::string($cfg['authorize_url'] ?? null).'?'.http_build_query($params);
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $provider, string $code, string $clientId, string $clientSecret, string $redirectUri, ?string $codeVerifier = null): array
    {
        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];

        if ($codeVerifier !== null && $codeVerifier !== '') {
            $payload['code_verifier'] = $codeVerifier;
        }

        return $this->tokenRequest($provider, $clientId, $clientSecret, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshToken(string $provider, string $refreshToken, string $clientId, string $clientSecret): array
    {
        $cfg = $this->provider($provider);
        $scopeMap = Cast::arr($cfg['scopes'] ?? null);

        return $this->tokenRequest($provider, $clientId, $clientSecret, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', Cast::strings($scopeMap['email'] ?? null)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function userInfo(string $provider, string $accessToken): array
    {
        $cfg = $this->provider($provider);

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->get(Cast::string($cfg['userinfo_url'] ?? null))
            ->throw()
            ->json();

        return Cast::arr($response);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function tokenRequest(string $provider, string $clientId, string $clientSecret, array $payload): array
    {
        $cfg = $this->provider($provider);
        $auth = $cfg['token_auth'] ?? 'body';

        if ($auth === 'body') {
            $payload['client_id'] = $clientId;
            $payload['client_secret'] = $clientSecret;
        }

        $request = Http::asForm()->acceptJson();

        if ($auth === 'basic') {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        }

        $tokenUrl = Cast::string($cfg['token_url'] ?? null);
        $response = ($cfg['token_method'] ?? 'post') === 'get'
            ? $request->get($tokenUrl, $payload)
            : $request->post($tokenUrl, $payload);

        return Cast::arr($response->throw()->json());
    }
}
