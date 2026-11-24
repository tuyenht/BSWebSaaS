<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;

/**
 * Resolves which social-login (Google/Facebook/…) OAuth app a sign-in uses, from two tiers:
 *
 *  - the platform's shared app (platform_settings group 'oauth_social', key = provider):
 *    {client_id, client_secret, enabled (master switch), status ('ok'|'error'|null), checked_at,
 *    fails/fails_for (refusals in a row, see CheckSocialApps)};
 *  - the website's own choice (site_settings group 'oauth_social', key = provider):
 *    {on (the site's switch), own (use its own app), client_id, client_secret}.
 *
 * A website's own app wins once it is chosen and complete; otherwise the shared app serves, when
 * it is complete, switched on and not found broken by the periodic check. A provider is enabled
 * on a website only when the site's switch is on and one of the two apps is ready.
 *
 * Rows written before the switch existed ({client_id, client_secret, enabled}) still read: a row
 * with a client id means the site's own app and its old `enabled` is the switch; a row with no
 * client id is not a choice to turn the shared app off (there was none then), so it reads as on.
 *
 * On a host with no website (the platform console) only the platform tier applies, and nothing
 * here queries a tenant table.
 */
final readonly class SocialAuth
{
    public function __construct(
        private SettingsRepository $tenant,
        private PlatformSettings $platform,
    ) {}

    public function enabled(string $provider): bool
    {
        $site = $this->siteRow($provider);

        return self::switchedOn($site) && $this->resolve($provider, $site) !== null;
    }

    /**
     * The app a sign-in with this provider uses here: the website's own when chosen and complete,
     * else the platform's shared one when ready, else none. Ignores the site's switch (see enabled()).
     *
     * @return array<string, mixed>|null
     */
    public function config(string $provider): ?array
    {
        return $this->resolve($provider, $this->siteRow($provider));
    }

    public function clientSecret(string $provider): ?string
    {
        $cfg = $this->config($provider);
        $secret = $cfg['client_secret'] ?? null;

        return is_string($secret) ? SecretCipher::decrypt($secret) : null;
    }

    /** The platform's shared app is complete, switched on and not found broken by the last check. */
    public function sharedReady(string $provider): bool
    {
        return self::platformReady($this->platform->get('oauth_social', $provider));
    }

    /** The website's own switch for this provider (on by default). */
    public function siteOn(string $provider): bool
    {
        return self::switchedOn($this->siteRow($provider));
    }

    /** The website chose to use its own app for this provider. */
    public function usesOwn(string $provider): bool
    {
        return self::choosesOwn($this->siteRow($provider));
    }

    /** The website chose its own app and both its client id and secret are saved. */
    public function ownReady(string $provider): bool
    {
        return self::ownComplete($this->siteRow($provider));
    }

    /**
     * The website's whole choice for this provider from one read of each tier, for the settings
     * screen: its switch, whether it chose its own app, whether that app is complete, and whether
     * the platform's shared app is ready.
     *
     * @return array{on: bool, own: bool, own_ready: bool, shared_ready: bool}
     */
    public function siteChoice(string $provider): array
    {
        $site = $this->siteRow($provider);

        return [
            'on' => self::switchedOn($site),
            'own' => self::choosesOwn($site),
            'own_ready' => self::ownComplete($site),
            'shared_ready' => $this->sharedReady($provider),
        ];
    }

    /** How many accounts have linked this provider (across the platform). */
    public function usersCount(string $provider): int
    {
        return SocialIdentity::query()->where('provider', $provider)->count();
    }

    /**
     * How many accounts have linked each provider, in one query.
     *
     * @param  list<string>  $providers
     * @return array<string, int>
     */
    public function usersCounts(array $providers): array
    {
        $counts = SocialIdentity::query()
            ->whereIn('provider', $providers)
            ->groupBy('provider')
            ->selectRaw('provider, count(*) as linked')
            ->pluck('linked', 'provider');

        $out = [];
        foreach ($providers as $provider) {
            $out[$provider] = Cast::int($counts[$provider] ?? 0);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $site
     * @return array<string, mixed>|null
     */
    private function resolve(string $provider, ?array $site): ?array
    {
        if ($site !== null && self::ownComplete($site)) {
            return $site;
        }

        $platform = $this->platform->get('oauth_social', $provider);

        return self::platformReady($platform) ? $platform : null;
    }

    /**
     * The website's row, or null on a host with no website (no tenant query is made there).
     *
     * @return array<string, mixed>|null
     */
    private function siteRow(string $provider): ?array
    {
        if (! app(CurrentSite::class)->hasId()) {
            return null;
        }

        return $this->tenant->get('oauth_social', $provider);
    }

    /**
     * A website row's switch, as read for a row without `on` too (a row from before the switch).
     *
     * @param  array<string, mixed>|null  $site
     */
    public static function switchedOn(?array $site): bool
    {
        if ($site === null) {
            return true;
        }
        if (array_key_exists('on', $site)) {
            return (bool) $site['on'];
        }

        // A row from before the switch: with its own client id, the old `enabled` was the switch.
        return self::filledString($site['client_id'] ?? null) ? (bool) ($site['enabled'] ?? true) : true;
    }

    /**
     * Whether a website row chose its own app, as read for a row without `own` too.
     *
     * @param  array<string, mixed>|null  $site
     */
    public static function choosesOwn(?array $site): bool
    {
        if ($site === null) {
            return false;
        }
        if (array_key_exists('own', $site)) {
            return (bool) $site['own'];
        }

        return self::filledString($site['client_id'] ?? null);
    }

    /** @param  array<string, mixed>|null  $site */
    private static function ownComplete(?array $site): bool
    {
        return self::choosesOwn($site)
            && self::filledString($site['client_id'] ?? null)
            && self::filledString($site['client_secret'] ?? null);
    }

    /** @param  array<string, mixed>|null  $platform */
    private static function platformReady(?array $platform): bool
    {
        return $platform !== null
            && self::filledString($platform['client_id'] ?? null)
            && self::filledString($platform['client_secret'] ?? null)
            && ($platform['enabled'] ?? true) !== false
            && ($platform['status'] ?? null) !== 'error';
    }

    private static function filledString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
