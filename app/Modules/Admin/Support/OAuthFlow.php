<?php

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

        return $cfg;
    }

    /**
     * The ONE canonical redirect URI registered with every provider — on the project's
     * main domain, shared by every tenant + the platform (the callback resolves the origin
     * from state). Config-driven (OAUTH_CALLBACK_URL); defaults to the platform host.
     */
    public static function callbackUrl(): string
    {
        $url = (string) config('admin.oauth_callback_url', '');
        if ($url !== '') {
            return rtrim($url, '/');
        }

        $host = (string) config('tenancy.platform_host', '');
        if ($host !== '') {
            return 'https://'.$host.'/oauth/callback';
        }

        return route('oauth.callback');
    }

    public function authorizeUrl(string $provider, string $purpose, string $clientId, string $redirectUri, string $state, ?string $codeChallenge = null): string
    {
        $cfg = $this->provider($provider);
        $scopes = $cfg['scopes'][$purpose] ?? $cfg['scopes']['login'] ?? [];

        $params = array_merge([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ], $cfg['authorize_params'] ?? []);

        // PKCE (S256) — binds this authorization request to the token exchange below.
        if ($codeChallenge !== null) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return $cfg['authorize_url'].'?'.http_build_query($params);
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

        return $this->tokenRequest($provider, $clientId, $clientSecret, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', $cfg['scopes']['email'] ?? []),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function userInfo(string $provider, string $accessToken): array
    {
        $cfg = $this->provider($provider);

        return Http::withToken($accessToken)
            ->acceptJson()
            ->get($cfg['userinfo_url'])
            ->throw()
            ->json() ?? [];
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

        $response = ($cfg['token_method'] ?? 'post') === 'get'
            ? $request->get($cfg['token_url'], $payload)
            : $request->post($cfg['token_url'], $payload);

        return $response->throw()->json() ?? [];
    }
}
