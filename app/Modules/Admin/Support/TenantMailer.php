<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Support\Mail\MailSendContext;
use App\Modules\Shared\Contracts\TenantMailConfigurator;
use App\Modules\Shared\Support\Cast;
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
final readonly class TenantMailer implements TenantMailConfigurator
{
    private const string OWN_FAILING_KEY = 'mail-own-outbox-failing:';

    public function __construct(
        private SettingsRepository $tenant,
        private PlatformSettings $platform,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(): ?array
    {
        return $this->pick()[0];
    }

    /**
     * The sender resolve() picks, and whether it is the site's own (not the platform's).
     *
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function pick(): array
    {
        $tenant = $this->tenant->get('email');
        if ($this->usable($tenant)) {
            return [$tenant, true];
        }

        $platform = $this->platform->get('email');
        if ($this->usable($platform)) {
            return [$platform, false];
        }

        return [null, false];
    }

    public function isConfigured(): bool
    {
        return $this->resolve() !== null;
    }

    /**
     * Whether mail can leave with this site's own sender as read by the caller (null = none), else
     * with the platform's: the same order as resolve(), for a caller that already read the site row.
     *
     * @param  array<string, mixed>|null  $own
     */
    public function canSendWith(?array $own): bool
    {
        return $this->usable($own) || $this->usable($this->platform->get('email'));
    }

    /**
     * When the site's own outbox is picked but cannot send (a revoked or expired sign-in, a key that
     * no longer decrypts), mail leaves through the platform's shared outbox instead of being lost,
     * and the «Email» page is told the own outbox is failing. A test send passes $fallback = false:
     * it must report the outbox the site picked, not whichever one happened to work.
     */
    public function apply(bool $fallback = true): bool
    {
        // Purge cached transports FIRST so a warmed Octane worker cannot reuse the previous
        // request/tenant's live transport object regardless of what we configure below.
        Mail::purge('smtp');
        Mail::purge('admin_xoauth2');

        // What is set up below is noted for the mail log and the daily limit (MailSendContext).
        $context = app(MailSendContext::class);
        $context->begin();

        [$cfg, $siteOwn] = $this->pick();
        if ($cfg === null) {
            // No email override found anywhere in the resolution chain. Force the global
            // config to a safe no-op mailer so a previous request's SMTP config cannot
            // bleed through in the same Octane worker.
            config(['mail.default' => 'log']);
            $context->applied('platform', 'log', 'config');

            return false;
        }

        $applied = $this->applyConfig($cfg, $siteOwn);
        $via = $siteOwn ? 'own' : 'platform';

        if ($siteOwn) {
            $this->noteOwnOutbox($applied);
            $platform = $this->platform->get('email');
            if (! $applied && $fallback && is_array($platform) && $this->usable($platform)) {
                $applied = $this->applyConfig($platform, false);
                $via = 'platform';
            }
        }

        if (! $applied) {
            // A sender that could not be set up may return before writing any config: never leave
            // the previous request's live mailer (another site's server) in place in this worker.
            config(['mail.default' => 'log']);
            $context->applied($via, 'log', $context->hostRefused ? 'refused_host' : 'config');

            return false;
        }

        $this->applyTenantIdentity();
        $context->applied($via, Cast::string(config('mail.default')));

        return true;
    }

    /**
     * The outbox the website's next mail would try first: its own when one is set up and did not
     * fail on its last send, else the platform's. For a record made after the send's own context is
     * gone (a queued mail that failed for good); the send itself is told by apply().
     *
     * @return 'own'|'platform'
     */
    public function outbox(): string
    {
        return $this->pick()[1] && ! $this->ownOutboxFailing() ? 'own' : 'platform';
    }

    /** @param array<string, mixed> $cfg */
    private function applyConfig(array $cfg, bool $siteOwn): bool
    {
        return match ($cfg['method'] ?? 'smtp_password') {
            'smtp_password' => $this->applySmtpPassword($cfg, $siteOwn),
            'oauth2' => $this->applyOAuth2($cfg),
            default => false,
        };
    }

    /** Whether the site's own outbox failed on its last send (cleared by its next good one). */
    public function ownOutboxFailing(): bool
    {
        $current = app(CurrentSite::class);

        return $current->hasId() && Cache::has(self::OWN_FAILING_KEY.$current->id());
    }

    /** The website just set up or reconnected its own outbox: judge it again on its next send. */
    public function forgetOwnOutboxFailing(): void
    {
        $this->noteOwnOutbox(true);
    }

    private function noteOwnOutbox(bool $ok): void
    {
        $current = app(CurrentSite::class);
        if (! $current->hasId()) {
            return;
        }

        $key = self::OWN_FAILING_KEY.$current->id();
        if ($ok) {
            Cache::forget($key);
        } else {
            Cache::put($key, true, now()->addDays(7));
        }
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
        $tenant = is_array($tenant) ? $tenant : [];

        $explicit = $tenant['from_name'] ?? null;
        $brand = (is_string($explicit) && $explicit !== '') ? $explicit : $this->siteName($current->id());

        if ($brand !== null) {
            config(['mail.from.name' => $brand]);
        }
        // Reply-To is applied per-message by the SetTenantReplyTo listener (stateless — no Octane
        // reply-to leak), reading the site's reply_to setting at send time.
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
        // A website that picked the platform's shared outbox keeps its own setup stored, unused.
        if (! is_array($cfg) || ($cfg['provider'] ?? null) === 'platform') {
            return false;
        }

        if (($cfg['method'] ?? 'smtp_password') === 'smtp_password') {
            // A blank password is not a usable credential — fall through (tenant → platform → log)
            // rather than attempting SMTP auth (or decrypting an empty payload) and failing the send.
            // A mail service's server comes from the relay table, its login from it or its own field.
            return MailRelay::ready($cfg);
        }

        $oauth = Cast::arr($cfg['oauth'] ?? null);

        return ! empty($oauth['provider']) && ! empty($oauth['refresh_token']);
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function applySmtpPassword(array $cfg, bool $siteOwn): bool
    {
        // A mail service sends through its own relay, its server taken from the relay table, never
        // from what is stored; «Email khác» through the server the website typed in.
        $smtp = MailRelay::smtpOf($cfg);
        $from = $cfg['from_address'] ?? MailRelay::defaultFrom($cfg);
        $password = is_string($smtp['password'] ?? null) ? SecretCipher::decrypt($smtp['password']) : null;
        $port = Cast::int($smtp['port'] ?? null, 587);
        $encryption = is_string($smtp['encryption'] ?? null) ? $smtp['encryption'] : 'tls';

        // A site's own server never leads to a private address or a non-mail port, even one saved
        // before that was checked or whose name now resolves elsewhere: send nothing (log mailer).
        // The platform's sender is the operator's and may be a relay on the private network.
        if ($siteOwn) {
            $guard = app(MailHostGuard::class);
            $host = Cast::string($smtp['host'] ?? null);
            $refusal = $guard->allowsPort($port) ? $guard->refusal($host) : 'port';
            // A name that does not resolve right now (a passing DNS fault) is left to the connection:
            // it fails there and the queued mail is tried again, instead of going to the log for good.
            if ($refusal !== null && $refusal !== MailHostGuard::UNRESOLVABLE) {
                config(['mail.default' => 'log']);
                app(MailSendContext::class)->hostRefused = true;
                // Mail is held back, so it must not vanish unseen (lead notices, password resets).
                report(new MailHostRefused($host, $port, $refusal, app(CurrentSite::class)->hasId() ? app(CurrentSite::class)->id() : null));

                return false;
            }
        }

        // A stored password that no longer decrypts (a rotated key) reads as ''; sending would fail
        // at the server, so count the sender as not set up and let apply() fall back.
        if (is_string($smtp['password'] ?? null) && $smtp['password'] !== '' && $password === '') {
            return false;
        }

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
            // Postmark's login is its key (never stored in the clear).
            'mail.mailers.smtp.username' => MailRelay::loginIsKey(MailRelay::of($cfg)) ? $password : ($smtp['username'] ?? null),
            'mail.mailers.smtp.password' => $password,
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
            // Google, Microsoft and Yahoo send only as the mailbox signed in, so its address is the
            // sender (the Email screen shows it locked); a stored «Địa chỉ gửi đi» is for another outbox.
            'mail.from.address' => $username,
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
