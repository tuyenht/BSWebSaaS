<?php

namespace App\Modules\Admin\Support;

/**
 * Resolves social-login (Google/Facebook/…) OAuth app credentials with the same hybrid
 * precedence as mail: tenant override (site_settings group 'oauth_social', key = provider)
 * → platform default (platform_settings) → none. A provider is "enabled" only once its
 * client id + secret are configured; until then the login buttons render disabled.
 */
final class SocialAuth
{
    public function __construct(
        private readonly SettingsRepository $tenant,
        private readonly PlatformSettings $platform,
    ) {}

    public function enabled(string $provider): bool
    {
        $cfg = $this->config($provider);

        return $cfg !== null
            && ! empty($cfg['client_id'])
            && ! empty($cfg['client_secret'])
            && ($cfg['enabled'] ?? true) !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function config(string $provider): ?array
    {
        $tenant = $this->tenant->get('oauth_social', $provider);
        if (is_array($tenant) && ! empty($tenant['client_id'])) {
            return $tenant;
        }

        $platform = $this->platform->get('oauth_social', $provider);
        if (is_array($platform) && ! empty($platform['client_id'])) {
            return $platform;
        }

        return null;
    }

    public function clientSecret(string $provider): ?string
    {
        $cfg = $this->config($provider);
        $secret = $cfg['client_secret'] ?? null;

        return is_string($secret) ? SecretCipher::decrypt($secret) : null;
    }
}
