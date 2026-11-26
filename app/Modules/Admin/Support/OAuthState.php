<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Str;

/**
 * The `state` value of an OAuth round trip: `<random>.<base64url(origin)>.<signature>`.
 *
 * The single-use cache entry keyed by the whole value stays the only authority for the flow
 * (provider, purpose, PKCE verifier, browser nonce, user). The origin rides along in the value
 * itself, signed with the app key, for one job only: when that entry is gone (the person stayed
 * on the provider's screen past its lifetime, or the callback address was opened twice), the
 * callback can still send the person back to the website they started from instead of a dead
 * end. A value whose signature does not hold yields no origin.
 */
final class OAuthState
{
    /** Longer than any value mint() makes for a real host name; nothing longer is decoded. */
    private const int MAX_LENGTH = 1024;

    public static function mint(string $origin): string
    {
        $random = Str::random(40);
        $encoded = self::encode($origin);

        return $random.'.'.$encoded.'.'.self::sign($random, $encoded);
    }

    /** The origin a state value was made for, or null when it is not one this app signed. */
    public static function origin(mixed $state): ?string
    {
        if (! is_string($state) || strlen($state) > self::MAX_LENGTH || self::key() === '') {
            return null;
        }

        $parts = explode('.', $state);
        if (count($parts) !== 3) {
            return null;
        }

        [$random, $encoded, $signature] = $parts;
        if ($random === '' || $encoded === '' || ! hash_equals(self::sign($random, $encoded), $signature)) {
            return null;
        }

        $origin = base64_decode(strtr($encoded, '-_', '+/'), true);

        // Only ever a bare scheme + host (+ port), as start() records it: never a path to follow.
        return is_string($origin) && preg_match('~\Ahttps?://[A-Za-z0-9.\-]+(:\d{1,5})?\z~', $origin) === 1
            ? $origin
            : null;
    }

    private static function sign(string $random, string $encoded): string
    {
        return self::encode(hash_hmac('sha256', 'oauth-state|'.$random.'|'.$encoded, self::key(), true));
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
