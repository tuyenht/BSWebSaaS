<?php

namespace App\Modules\Forms\Services;

use App\Modules\Admin\Support\Cast;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile server-side verification (§23/R20). When no secret is configured
 * (e.g. local/dev) verification is SKIPPED (returns true) so the form still works behind the
 * honeypot + rate-limit; it never fails open silently when a secret IS set — a missing/invalid
 * token then returns false and the submission is flagged for review.
 */
final class TurnstileVerifier
{
    public function verify(?string $token, ?string $ip = null): bool
    {
        $secret = Cast::string(config('services.turnstile.secret_key'));

        if ($secret === '') {
            return true; // Not configured — rely on honeypot + rate-limit.
        }

        if (! is_string($token) || $token === '') {
            return false;
        }

        $response = Http::asForm()->post(Cast::string(config('services.turnstile.verify_url')), [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);

        return $response->successful() && $response->json('success') === true;
    }
}
