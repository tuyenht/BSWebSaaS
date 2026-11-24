<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Shared\Support\Cast;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the provider whether a social-login app's client id and secret are real, without a user:
 *
 *  - Google: an authorization-code exchange with a code that cannot exist. A known client with the
 *    right secret gets `invalid_grant` (the code is refused, the client is not); an unknown client
 *    or a wrong secret gets `invalid_client` / `unauthorized_client`.
 *  - Facebook: an app access token (client_credentials). A token back means the keys are right;
 *    error 101 (Invalid Client ID) or an error about the client secret means they are not.
 *
 * Anything else — a network fault, a timeout, an answer not listed above — is Unknown, so a blip at
 * the provider never hides a working sign-in button. The secret goes only to the provider: it is
 * never logged, and neither is the request (a Facebook request carries it in its address).
 */
final class SocialAppCheck
{
    private const int TIMEOUT_SECONDS = 5;

    /** The authorization code sent to Google: one that no consent screen ever issued. */
    private const string PROBE_CODE = 'bs-check';

    public function __construct(private readonly OAuthFlow $flow) {}

    public function check(string $provider, string $clientId, string $clientSecret): SocialAppVerdict
    {
        if ($clientId === '' || $clientSecret === '') {
            return SocialAppVerdict::Invalid;
        }

        try {
            return match ($provider) {
                'google' => $this->google($clientId, $clientSecret),
                'facebook' => $this->facebook($clientId, $clientSecret),
                default => SocialAppVerdict::Unknown,
            };
        } catch (Throwable $e) {
            // The exception's text may hold the request address (and with it the secret): log only its kind.
            Log::info('Social app check could not reach the provider.', ['provider' => $provider, 'error' => $e::class]);

            return SocialAppVerdict::Unknown;
        }
    }

    private function google(string $clientId, string $clientSecret): SocialAppVerdict
    {
        $response = Http::asForm()->acceptJson()->timeout(self::TIMEOUT_SECONDS)->connectTimeout(self::TIMEOUT_SECONDS)
            ->post(Cast::string($this->flow->provider('google')['token_url'] ?? null), [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => self::PROBE_CODE,
                'grant_type' => 'authorization_code',
                'redirect_uri' => OAuthFlow::callbackUrl(),
            ]);

        return match (Cast::string($this->body($response)['error'] ?? null)) {
            'invalid_client', 'unauthorized_client' => SocialAppVerdict::Invalid,
            'invalid_grant' => SocialAppVerdict::Valid,
            default => SocialAppVerdict::Unknown,
        };
    }

    private function facebook(string $clientId, string $clientSecret): SocialAppVerdict
    {
        $response = Http::acceptJson()->timeout(self::TIMEOUT_SECONDS)->connectTimeout(self::TIMEOUT_SECONDS)
            ->get(Cast::string($this->flow->provider('facebook')['token_url'] ?? null), [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ]);

        $body = $this->body($response);
        if ($response->successful() && Cast::string($body['access_token'] ?? null) !== '') {
            return SocialAppVerdict::Valid;
        }

        $error = Cast::arr($body['error'] ?? null);
        $code = $error['code'] ?? null;
        $message = strtolower(Cast::string($error['message'] ?? null));

        return match (true) {
            $code === 101 || $code === '101' => SocialAppVerdict::Invalid,
            str_contains($message, 'client secret') => SocialAppVerdict::Invalid,
            default => SocialAppVerdict::Unknown,
        };
    }

    /** @return array<string, mixed> */
    private function body(Response $response): array
    {
        return Cast::arr($response->json());
    }
}
