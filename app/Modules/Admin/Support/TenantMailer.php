<?php

namespace App\Modules\Admin\Support;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Resolves and applies the effective email configuration onto the live mail config so
 * admin mail (password reset, notifications) goes out via the right mailer.
 *
 * Hybrid resolution: tenant override (site_settings group 'email') → platform default
 * (platform_settings group 'email') → log (safe no-op, guaranteed by apply()).
 *
 * Two methods: 'smtp_password' (host/port/user/password) and 'oauth2' (Google/Microsoft/
 * Yahoo) — the latter mints a short-lived access token from the stored refresh token and
 * sends over XOAUTH2 (transport registered in AdminServiceProvider). Secrets are AES-256-GCM
 * decrypted at apply time via SecretCipher — never logged.
 *
 * Octane isolation guarantee: apply() ALWAYS leaves the mailer in a deterministic state:
 * — resolved config found → smtp or admin_xoauth2 (tenant/platform SMTP or OAuth setting)
 * — no config found       → config('mail.default') forced to 'log'; callers may still
 *                           send (messages go to the log channel, never to another tenant's
 *                           SMTP). This prevents cross-tenant SMTP bleed under Octane where
 *                           the config repo is a warmed singleton shared across requests.
 * Cached mailer transports ('smtp', 'admin_xoauth2') are purged on every call so a
 * warmed Octane worker cannot reuse a previous request/tenant's live transport object.
 */
final class TenantMailer
{
    public function __construct(
        private readonly SettingsRepository $tenant,
        private readonly PlatformSettings $platform,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(): ?array
    {
        $tenant = $this->tenant->get('email');
        if ($this->usable($tenant)) {
            return $tenant;
        }

        $platform = $this->platform->get('email');
        if ($this->usable($platform)) {
            return $platform;
        }

        return null;
    }

    public function isConfigured(): bool
    {
        return $this->resolve() !== null;
    }

    public function apply(): bool
    {
        // Purge cached transports FIRST so a warmed Octane worker cannot reuse the previous
        // request/tenant's live transport object regardless of what we configure below.
        Mail::purge('smtp');
        Mail::purge('admin_xoauth2');

        $cfg = $this->resolve();
        if ($cfg === null) {
            // No email override found anywhere in the resolution chain. Force the global
            // config to a safe no-op mailer so a previous request's SMTP config cannot
            // bleed through in the same Octane worker.
            config(['mail.default' => 'log']);

            return false;
        }

        $applied = match ($cfg['method'] ?? 'smtp_password') {
            'smtp_password' => $this->applySmtpPassword($cfg),
            'oauth2' => $this->applyOAuth2($cfg),
            default => false,
        };

        if ($applied) {
            $this->applyTenantIdentity();
        }

        return $applied;
    }

    /**
     * Override the sender NAME with the tenant's own brand so a shared-SMTP send still shows the
     * customer's identity (e.g. "Cơ Khí An Vinh"), not the platform's ("BSWebSaaS"). Uses the
     * tenant's explicitly-set from_name, else the site name. No-op on the platform plane (no site
     * resolved) so operator mail keeps the platform sender. Set via config each apply() → Octane-safe.
     * (Reply-To routing to the customer is a follow-up — it needs a per-request reset to avoid leaks.)
     */
    private function applyTenantIdentity(): void
    {
        $current = app(CurrentSite::class);
        if (! $current->hasId()) {
            return;
        }

        $tenant = $this->tenant->get('email');
        $explicit = is_array($tenant) ? ($tenant['from_name'] ?? null) : null;
        $brand = (is_string($explicit) && $explicit !== '') ? $explicit : $this->siteName($current->id());

        if ($brand !== null) {
            config(['mail.from.name' => $brand]);
        }
    }

    private function siteName(int $siteId): ?string
    {
        $name = Site::query()->find($siteId)?->name;

        return (is_string($name) && $name !== '') ? $name : null;
    }

    /**
     * @param  array<string, mixed>|null  $cfg
     */
    private function usable(?array $cfg): bool
    {
        if (! is_array($cfg)) {
            return false;
        }

        if (($cfg['method'] ?? 'smtp_password') === 'smtp_password') {
            $smtp = Cast::arr($cfg['smtp'] ?? null);

            // A blank password is not a usable credential — fall through (tenant → platform → log)
            // rather than attempting SMTP auth (or decrypting an empty payload) and failing the send.
            return ! empty($smtp['host']) && ! empty($smtp['username']) && ! empty($smtp['password']);
        }

        $oauth = Cast::arr($cfg['oauth'] ?? null);

        return ! empty($oauth['provider']) && ! empty($oauth['refresh_token']);
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function applySmtpPassword(array $cfg): bool
    {
        $smtp = Cast::arr($cfg['smtp'] ?? null);
        $from = $cfg['from_address'] ?? ($smtp['username'] ?? null);
        $port = Cast::int($smtp['port'] ?? null, 587);
        $encryption = is_string($smtp['encryption'] ?? null) ? $smtp['encryption'] : 'tls';

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $smtp['host'] ?? null,
            'mail.mailers.smtp.port' => $port,
            // Pin the Symfony DSN scheme from the tenant's OWN encryption/port so the send never
            // inherits a stale/invalid global MAIL_SCHEME. Symfony's SMTP transport accepts only
            // "smtp" (STARTTLS, typically :587) or "smtps" (implicit TLS, :465) — the legacy "ssl"
            // value throws UnsupportedSchemeException. SSL or port 465 → smtps; otherwise smtp.
            'mail.mailers.smtp.scheme' => ($encryption === 'ssl' || $port === 465) ? 'smtps' : 'smtp',
            'mail.mailers.smtp.encryption' => $encryption,
            'mail.mailers.smtp.username' => $smtp['username'] ?? null,
            'mail.mailers.smtp.password' => is_string($smtp['password'] ?? null) ? SecretCipher::decrypt($smtp['password']) : null,
            'mail.from.address' => $from,
            'mail.from.name' => $cfg['from_name'] ?? config('admin.brand.name'),
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function applyOAuth2(array $cfg): bool
    {
        $oauth = Cast::arr($cfg['oauth'] ?? null);
        $provider = $oauth['provider'] ?? null;
        $refreshEnc = $oauth['refresh_token'] ?? null;
        $clientId = $oauth['client_id'] ?? null;
        $clientSecret = is_string($oauth['client_secret'] ?? null) ? SecretCipher::decrypt($oauth['client_secret']) : null;

        $providerCfg = is_string($provider) ? config("oauth.providers.$provider") : null;
        if (! is_array($providerCfg) || ! is_string($refreshEnc) || ! is_string($clientId) || $clientSecret === null) {
            return false;
        }

        $accessToken = $this->accessToken($provider, SecretCipher::decrypt($refreshEnc), $clientId, $clientSecret);
        if ($accessToken === null) {
            return false;
        }

        $smtp = Cast::arr($providerCfg['smtp'] ?? null);
        $username = $oauth['email'] ?? ($cfg['from_address'] ?? null);

        config([
            'mail.default' => 'admin_xoauth2',
            'mail.mailers.admin_xoauth2.transport' => 'admin_xoauth2',
            'mail.mailers.admin_xoauth2.host' => $smtp['host'] ?? null,
            'mail.mailers.admin_xoauth2.port' => Cast::int($smtp['port'] ?? null, 587),
            'mail.mailers.admin_xoauth2.encryption' => $smtp['encryption'] ?? 'tls',
            'mail.mailers.admin_xoauth2.username' => $username,
            'mail.mailers.admin_xoauth2.access_token' => $accessToken,
            'mail.from.address' => $cfg['from_address'] ?? $username,
            'mail.from.name' => $cfg['from_name'] ?? config('admin.brand.name'),
        ]);

        return true;
    }

    private function accessToken(string $provider, string $refreshToken, string $clientId, string $clientSecret): ?string
    {
        // Cache the minted token until just before expiry so each send doesn't re-hit the
        // token endpoint; keyed by the refresh token so rotating creds invalidates it.
        $cacheKey = 'mail-oauth-access:'.$provider.':'.sha1($refreshToken);
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        // Serialize concurrent refreshes so parallel sends don't stampede the token endpoint
        // or race each other's writes.
        $lock = Cache::lock($cacheKey.':lock', 15);

        try {
            $lock->block(8);
        } catch (LockTimeoutException) {
            $cached = Cache::get($cacheKey);

            return is_string($cached) ? $cached : null;
        }

        try {
            $cached = Cache::get($cacheKey); // a peer may have refreshed while we waited
            if (is_string($cached)) {
                return $cached;
            }

            try {
                $tokens = app(OAuthFlow::class)->refreshToken($provider, $refreshToken, $clientId, $clientSecret);
            } catch (Throwable $e) {
                report($e);

                return null;
            }

            $access = $tokens['access_token'] ?? null;
            if (! is_string($access) || $access === '') {
                return null;
            }

            Cache::put($cacheKey, $access, max(60, Cast::int($tokens['expires_in'] ?? null, 3600) - 60));

            return $access;
        } finally {
            $lock->release();
        }
    }
}
