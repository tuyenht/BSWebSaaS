<?php

namespace App\Modules\Identity\TwoFactor;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor (design.md §29 / C1). Secret + recovery codes are encrypted at
 * rest (model casts). Mandatory for platform operators; optional for tenants.
 *
 * A code from the app is accepted once (RFC 6238 §5.2): wherever a member types it (sign-in, a
 * re-check before a sensitive step, turning the app on), the 30-second step it matched is taken
 * for that member, and that step or an earlier one never passes again. What is taken lives in the
 * cache (shared by every worker), never in this object.
 */
final readonly class TwoFactorService
{
    private const int RECOVERY_CODES = 8;

    private const int VERIFY_WINDOW = 2; // tolerate ±2 periods of clock skew

    /** How long a taken step is remembered: longer than the 2 × VERIFY_WINDOW + 1 periods of 30 s a code can pass in. */
    private const int TAKEN_TTL = 300;

    public function __construct(private Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * Whether the code fits the secret now, remembering nothing. A code a member types to get in or
     * to go on goes through verifyOnce instead.
     */
    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code, self::VERIFY_WINDOW) !== false;
    }

    /**
     * The member's app code, accepted once: false for a wrong code and for a code whose step was
     * already taken (the same code again, an older one, or the same code sent twice side by side).
     * A wrong code is never remembered.
     */
    public function verifyOnce(User $user, string $code): bool
    {
        $secret = Cast::string($user->two_factor_secret);

        return $secret !== '' && $this->take($user, $secret, $code);
    }

    public function otpauthUri(User $user, string $secret): string
    {
        $issuer = is_string(config('app.name')) ? config('app.name') : 'BSWebSaaS';

        return $this->engine->getQRCodeUrl($issuer, $user->email, $secret);
    }

    public function qrSvg(User $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192), new SvgImageBackEnd));

        return $writer->writeString($this->otpauthUri($user, $secret));
    }

    /**
     * Enable 2FA once the user proves possession of the secret. Returns false on a
     * bad code (nothing is persisted).
     */
    public function enable(User $user, string $secret, string $code): bool
    {
        if (! $this->take($user, $secret, $code)) {
            return false;
        }

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $this->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return true;
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = is_array($user->two_factor_recovery_codes) ? $user->two_factor_recovery_codes : [];

        if (! in_array($code, $codes, true)) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_filter($codes, static fn ($c): bool => $c !== $code)),
        ])->save();

        return true;
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = self::RECOVERY_CODES): array
    {
        return array_map(
            static fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)),
            range(1, $count),
        );
    }

    /**
     * Check the code first (a wrong one leaves nothing behind), then take the step it matched. Only
     * a step after the last one taken can match; the atomic add settles two requests carrying the
     * same code (one gets it), and every taken step keeps its own key for TAKEN_TTL, so no write
     * order can let a taken step pass again. The last-step mark is a plain read and write: two
     * different codes sent side by side can leave it lower, so an older step nobody took may still
     * pass then. Keys carry a short hash of the secret: setting the app up again with a new secret
     * starts clean.
     */
    private function take(User $user, string $secret, string $code): bool
    {
        $id = Cast::int($user->getKey()).':'.substr(hash('sha256', $secret), 0, 16);
        $lastKey = 'two-factor:last-step:'.$id;
        $last = Cast::int(Cache::get($lastKey, 0));

        $step = $this->engine->verifyKeyNewer($secret, $code, $last, self::VERIFY_WINDOW);
        if (! is_int($step) || ! Cache::add('two-factor:taken:'.$id.':'.$step, true, self::TAKEN_TTL)) {
            return false;
        }
        Cache::put($lastKey, max($step, Cast::int(Cache::get($lastKey, 0))), self::TAKEN_TTL);

        return true;
    }
}
