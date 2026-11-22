<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The signed-in member proves again that it is them, before a step that hands out the website's
 * personal data: their own password, or the 6-digit code of their authenticator app.
 *
 * At most ATTEMPTS wrong tries per DECAY seconds, counted per member whichever of the two they
 * type (switching from the password to the code never buys more guesses); a success clears the
 * count. An empty field or a code that is not 6 digits is answered without counting a try. A code
 * is accepted once: the same code again, while its window is still open, counts as wrong, so a
 * code read over the member's shoulder cannot be replayed.
 */
final readonly class ConfirmIdentity
{
    public const int ATTEMPTS = 5;

    public const int DECAY = 300;

    /** How long a used code is remembered: longer than the ±2 periods of 30 s TwoFactorService accepts. */
    private const int USED_CODE_TTL = 300;

    public function __construct(private TwoFactorService $twoFactor) {}

    public function password(User $user, string $password): IdentityCheck
    {
        $hash = Cast::string($user->getAuthPassword());
        if ($hash === '') {
            return IdentityCheck::NoPassword;
        }
        if (RateLimiter::tooManyAttempts(self::key($user), self::ATTEMPTS)) {
            return IdentityCheck::Throttled;
        }
        if ($password === '') {
            return IdentityCheck::Missing;
        }
        if (! Hash::check($password, $hash)) {
            RateLimiter::hit(self::key($user), self::DECAY);

            return IdentityCheck::Wrong;
        }
        RateLimiter::clear(self::key($user));

        return IdentityCheck::Confirmed;
    }

    public function code(User $user, string $code): IdentityCheck
    {
        $secret = Cast::string($user->two_factor_secret);
        if ($user->two_factor_confirmed_at === null || $secret === '') {
            return IdentityCheck::NoTwoFactor;
        }
        if (RateLimiter::tooManyAttempts(self::key($user), self::ATTEMPTS)) {
            return IdentityCheck::Throttled;
        }
        $code = (string) preg_replace('/\s+/', '', $code);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return IdentityCheck::Missing;
        }
        // checked first, so a wrong code is never remembered as used; then the code is taken once
        // (an atomic add: two requests with the same code, only one gets it)
        if (! $this->twoFactor->verify($secret, $code)
            || ! Cache::add('confirm-identity:used:'.Cast::int($user->getKey()).':'.hash('sha256', $code), true, self::USED_CODE_TTL)) {
            RateLimiter::hit(self::key($user), self::DECAY);

            return IdentityCheck::Wrong;
        }
        RateLimiter::clear(self::key($user));

        return IdentityCheck::Confirmed;
    }

    /** Seconds until a throttled member may try again. */
    public function retryIn(User $user): int
    {
        return max(1, RateLimiter::availableIn(self::key($user)));
    }

    private static function key(User $user): string
    {
        return 'confirm-identity:'.Cast::int($user->getKey());
    }
}
