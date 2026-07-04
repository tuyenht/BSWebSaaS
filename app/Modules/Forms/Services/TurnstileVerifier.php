<?php

declare(strict_types=1);

namespace App\Modules\Forms\Services;

use App\Modules\Admin\Support\Cast;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile server-side verification (§23/R20). When no secret is configured
 * (e.g. local/dev) verification is SKIPPED (returns true) so the form still works behind the
 * honeypot + rate-limit; it never fails open silently when a secret IS set — a missing/invalid
 * token then returns false and the submission is flagged for review.
 */
final class TurnstileVerifier
{
    /**
     * Both keys present — the single signal to RENDER and ENFORCE the challenge together. Gating the
     * widget and the server check on one thing stops a half-set config from either showing a widget
     * that is never verified (ornamental) or enforcing a check with no widget (self-lockout).
     */
    public function isConfigured(): bool
    {
        return Cast::string(config('services.turnstile.site_key')) !== ''
            && Cast::string(config('services.turnstile.secret_key')) !== '';
    }

    /**
     * @param  bool  $failOpenOnTransportError  When Cloudflare ITSELF is unreachable (network error or
     *                                          5xx), the token could not be verified either way. The storefront keeps failing CLOSED (a lead is
     *                                          captured as spam, recoverable). Login passes true so a Cloudflare outage doesn't lock EVERY admin
     *                                          out — the token was present, only verification was impossible, and password + 2FA + rate-limit
     *                                          still gate. A missing/invalid token always fails, regardless of this flag.
     */
    public function verify(?string $token, ?string $ip = null, bool $failOpenOnTransportError = false): bool
    {
        $secret = Cast::string(config('services.turnstile.secret_key'));

        if ($secret === '') {
            return true; // Not configured — rely on honeypot + rate-limit.
        }

        if (! is_string($token) || $token === '') {
            return false; // A missing/blank token is a hard fail — never fail-open on this.
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post(Cast::string(config('services.turnstile.verify_url')), [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]);
        } catch (Throwable $e) {
            report($e); // Cloudflare unreachable / timed out.

            return $failOpenOnTransportError;
        }

        if (! $response->successful()) {
            return $failOpenOnTransportError; // Cloudflare 5xx — verification impossible, not a rejected token.
        }

        return $response->json('success') === true;
    }
}
