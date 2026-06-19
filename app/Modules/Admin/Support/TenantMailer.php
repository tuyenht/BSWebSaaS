<?php

namespace App\Modules\Admin\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Resolves and applies the effective email configuration onto the live mail config so
 * admin mail (password reset, notifications) goes out via the right mailer.
 *
 * Hybrid resolution: tenant override (site_settings group 'email') → platform default
 * (platform_settings group 'email') → null (caller falls back to the log mailer).
 *
 * Two methods: 'smtp_password' (host/port/user/password) and 'oauth2' (Google/Microsoft/
 * Yahoo) — the latter mints a short-lived access token from the stored refresh token and
 * sends over XOAUTH2 (transport registered in AdminServiceProvider). Secrets are AES-256-GCM
 * decrypted at apply time via SecretCipher — never logged.
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
        $cfg = $this->resolve();
        if ($cfg === null) {
            return false;
        }

        return match ($cfg['method'] ?? 'smtp_password') {
            'smtp_password' => $this->applySmtpPassword($cfg),
            'oauth2' => $this->applyOAuth2($cfg),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>|null  $cfg
     */
    private function usable(?array $cfg): bool
    {
        if (! is_array($cfg)) {
            return false;
        }

        return match ($cfg['method'] ?? 'smtp_password') {
            'smtp_password' => ! empty($cfg['smtp']['host']) && ! empty($cfg['smtp']['username']),
            default => ! empty($cfg['oauth']['provider']) && ! empty($cfg['oauth']['refresh_token']),
        };
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function applySmtpPassword(array $cfg): bool
    {
        $smtp = $cfg['smtp'] ?? [];
        $from = $cfg['from_address'] ?? ($smtp['username'] ?? null);

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $smtp['host'] ?? null,
            'mail.mailers.smtp.port' => (int) ($smtp['port'] ?? 587),
            'mail.mailers.smtp.encryption' => $smtp['encryption'] ?? 'tls',
            'mail.mailers.smtp.username' => $smtp['username'] ?? null,
            'mail.mailers.smtp.password' => isset($smtp['password']) ? SecretCipher::decrypt($smtp['password']) : null,
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
        $oauth = $cfg['oauth'] ?? [];
        $provider = $oauth['provider'] ?? null;
        $refreshEnc = $oauth['refresh_token'] ?? null;
        $clientId = $oauth['client_id'] ?? null;
        $clientSecret = isset($oauth['client_secret']) ? SecretCipher::decrypt($oauth['client_secret']) : null;

        $providerCfg = is_string($provider) ? config("oauth.providers.$provider") : null;
        if (! is_array($providerCfg) || ! is_string($refreshEnc) || ! is_string($clientId) || $clientSecret === null) {
            return false;
        }

        $accessToken = $this->accessToken($provider, SecretCipher::decrypt($refreshEnc), $clientId, $clientSecret);
        if ($accessToken === null) {
            return false;
        }

        $smtp = $providerCfg['smtp'] ?? [];
        $username = $oauth['email'] ?? ($cfg['from_address'] ?? null);

        config([
            'mail.default' => 'admin_xoauth2',
            'mail.mailers.admin_xoauth2.transport' => 'admin_xoauth2',
            'mail.mailers.admin_xoauth2.host' => $smtp['host'] ?? null,
            'mail.mailers.admin_xoauth2.port' => (int) ($smtp['port'] ?? 587),
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

            Cache::put($cacheKey, $access, max(60, (int) ($tokens['expires_in'] ?? 3600) - 60));

            return $access;
        } finally {
            $lock->release();
        }
    }
}
