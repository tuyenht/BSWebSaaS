<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Str;

/**
 * The `state` value of an OAuth round trip: `<random>.<base64url(origin)>.<issued at>.<signature>`.
 *
 * The single-use cache entry keyed by the whole value stays the only authority for the flow
 * (provider, purpose, PKCE verifier, browser nonce, user). The origin rides along in the value
 * itself, signed with the app key, for one job only: when that entry is gone (the person stayed
 * on the provider's screen past its lifetime, or the callback address was opened twice), the
 * callback can still send the person back to the website they started from instead of a dead
 * end. A value whose signature does not hold, or that is older than MAX_AGE, yields no origin, so
 * a signed value is never a lasting link to a website's sign-in page.
 */
final class OAuthState
{
    /** Longer than any value mint() makes for a real host name; nothing longer is decoded. */
    private const int MAX_LENGTH = 1024;

    /** Seconds a signed value still names its origin: well past the cache entry's lifetime. */
    private const int MAX_AGE = 3600;

    public static function mint(string $origin): string
    {
        $random = Str::random(40);
        $encoded = self::encode($origin);
        $issued = (string) now()->getTimestamp();

        return $random.'.'.$encoded.'.'.$issued.'.'.self::sign($random, $encoded, $issued);
    }

    /** The origin a state value was made for, or null when it is not one this app signed. */
    public static function origin(mixed $state): ?string
    {
        if (! is_string($state) || strlen($state) > self::MAX_LENGTH || self::key() === '') {
            return null;
        }

        $parts = explode('.', $state);
        if (count($parts) !== 4) {
            return null;
        }

        [$random, $encoded, $issued, $signature] = $parts;
        if ($random === '' || $encoded === '' || preg_match('/\A\d{1,12}\z/', $issued) !== 1
            || ! hash_equals(self::sign($random, $encoded, $issued), $signature)) {
            return null;
        }

        $age = now()->getTimestamp() - (int) $issued;
        if ($age < 0 || $age > self::MAX_AGE) {
            return null;
        }

        $origin = base64_decode(strtr($encoded, '-_', '+/'), true);

        // Only ever a bare scheme + host (+ port), as start() records it: never a path to follow.
        return is_string($origin) && preg_match('~\Ahttps?://[A-Za-z0-9.\-]+(:\d{1,5})?\z~', $origin) === 1
            ? $origin
            : null;
    }

    private static function sign(string $random, string $encoded, string $issued): string
    {
        return self::encode(hash_hmac('sha256', 'oauth-state|'.$random.'|'.$encoded.'|'.$issued, self::key(), true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function key(): string
    {
        return Cast::string(config('app.key'));
    }
}
