<?php

namespace App\Modules\Identity\TwoFactor;

use App\Modules\Identity\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor (design.md §29 / C1). Secret + recovery codes are encrypted at
 * rest (model casts). Mandatory for platform operators; optional for tenants.
 */
final class TwoFactorService
{
    private const RECOVERY_CODES = 8;

    private const VERIFY_WINDOW = 2; // tolerate ±2 periods of clock skew

    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function verify(string $secret, string $code): bool
    {
        // verifyKey returns the matched timestamp (int) or false.
        return $this->engine->verifyKey($secret, $code, self::VERIFY_WINDOW) !== false;
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
        if (! $this->verify($secret, $code)) {
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
}
