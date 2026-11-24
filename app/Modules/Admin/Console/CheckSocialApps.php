<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SocialAppCheck;
use App\Modules\Admin\Support\SocialAppVerdict;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Shared\Support\Cast;
use Illuminate\Console\Command;

/**
 * Checks the platform's shared social-login apps against their providers and records the result on
 * each app's row (platform_settings group 'oauth_social'): keys accepted → status 'ok' and the
 * refusal count back to zero; keys refused twice in a row → status 'error', which hides that
 * provider's button on every website using the shared app (the password still works), while a
 * first refusal only counts (`fails`, tied to the keys it was for by `fails_for`) and keeps the
 * status; no clear answer → nothing changes, so a network fault never hides a button. `checked_at` is written whenever the provider answered clearly; a changed status leaves an
 * audit row. Only apps with both a client id and a secret are checked; websites' own apps are not.
 */
class CheckSocialApps extends Command
{
    private const string GROUP = 'oauth_social';

    /** Refusals in a row, for the same keys, before an app is marked broken. */
    private const int FAILS_TO_ERROR = 2;

    #[\Override]
    protected $signature = 'social:check-apps';

    #[\Override]
    protected $description = "Check the platform's shared social-login apps with their providers and mark broken ones";

    public function handle(PlatformSettings $platform, SocialAppCheck $check): int
    {
        foreach (Cast::arr(config('oauth.providers')) as $provider => $cfg) {
            $provider = (string) $provider;
            if (! in_array('login', Cast::strings(Cast::arr($cfg)['supports'] ?? null), true)) {
                continue;
            }

            $row = $platform->get(self::GROUP, $provider);
            $clientId = Cast::string($row['client_id'] ?? null);
            $stored = Cast::string($row['client_secret'] ?? null);
            if ($row === null || $clientId === '' || $stored === '') {
                continue;
            }

            $secret = SecretCipher::decrypt($stored);
            if ($secret === '') {
                // Undecryptable (the settings key changed): nothing to check with.
                $this->warn("{$provider}: the stored secret cannot be read; skipped.");

                continue;
            }

            $verdict = $check->check($provider, $clientId, $secret);
            if ($verdict === SocialAppVerdict::Unknown) {
                $this->line("{$provider}: no clear answer from the provider; status kept.");

                continue;
            }

            // The keys may have been replaced while the provider answered: never stamp a verdict on
            // keys that were not the ones checked. The re-read and the write share one row lock, so
            // a save of new keys cannot land between them.
            $fingerprint = hash('sha256', $clientId."\0".$stored);
            $old = null;
            $new = null;
            $written = $platform->change(self::GROUP, $provider, function (?array $row) use ($clientId, $stored, $verdict, $fingerprint, &$old, &$new): ?array {
                if ($row === null || ($row['client_id'] ?? null) !== $clientId || ($row['client_secret'] ?? null) !== $stored) {
                    return null;
                }

                $old = Cast::stringOrNull($row['status'] ?? null);
                if ($verdict === SocialAppVerdict::Valid) {
                    $fails = 0;
                    $new = 'ok';
                } else {
                    // Only refusals of these same keys count: new keys start again from zero.
                    $fails = (($row['fails_for'] ?? null) === $fingerprint ? Cast::int($row['fails'] ?? null) : 0) + 1;
                    $new = $fails >= self::FAILS_TO_ERROR ? 'error' : $old;
                }
                $row['status'] = $new;
                $row['fails'] = $fails;
                $row['fails_for'] = $fails > 0 ? $fingerprint : null;
                $row['checked_at'] = now()->toIso8601String();

                return $row;
            });
            if ($written === null) {
                $this->line("{$provider}: the keys changed during the check; status kept.");

                continue;
            }

            if ($old !== $new) {
                Audit::record('platform.oauth_social.status', [
                    'site_id' => null,
                    'meta' => ['provider' => $provider, 'from' => $old, 'to' => $new],
                ]);
            }

            $this->line($verdict === SocialAppVerdict::Invalid && $new !== 'error'
                ? "{$provider}: keys refused once; status kept until a second refusal."
                : "{$provider}: ".Cast::string($new).'.');
        }

        return self::SUCCESS;
    }
}
