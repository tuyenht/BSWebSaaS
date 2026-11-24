<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Errors\AdminErrorPages;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Theme\AdminBrandColor;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Shared\Support\Cast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Meta's data-deletion callback for the platform's shared Facebook app. When a person removes the
 * app from their Facebook settings, Meta posts a `signed_request` here; it is checked with that
 * app's secret, the person's Facebook link (provider 'facebook', sub = their app-scoped user id) is
 * deleted, and Meta gets back a confirmation code and the address of a page showing it. An audit
 * row records that it happened and how many links went, never the person's Facebook id.
 *
 * Only the shared app is served here: a website with its own Facebook app names its own address in
 * that app. The request carries no session or CSRF token (it comes from Meta's servers); the
 * signature is its proof. A body not shaped like a signed request is refused before anything is
 * read from the database. A request must have been issued within the last day (and not more than
 * a few minutes ahead of this clock); the same signed request sent again within that day gets the
 * first answer back and deletes nothing more. A bad request gets a bare 400 that does not say what
 * was wrong.
 */
final readonly class FacebookDataDeletionController
{
    /** The rate limiter both addresses use (per IP), registered by AdminServiceProvider. */
    public const string LIMITER = 'oauth-facebook-deletion';

    private const string CACHE_PREFIX = 'oauth-fb-deletion:';

    /** Where an accepted signed request's answer is kept (by its hash) so a repeat gets it back. */
    private const string SEEN_PREFIX = 'oauth-fb-deletion-seen:';

    private const int KEEP_DAYS = 90;

    /** A signed request older than this is refused; the answer to one is kept a little longer. */
    private const int MAX_AGE_SECONDS = 86400;

    private const int SEEN_KEEP_SECONDS = 90000;

    /** How far ahead of this clock a request's issued_at may be (clock drift). */
    private const int MAX_AHEAD_SECONDS = 300;

    /** A signature (HMAC-SHA256: 32 bytes = 43 base64url characters, 44 padded), a dot, a payload. */
    private const string SHAPE = '~\A[A-Za-z0-9_\-+/]{43}=?\.[A-Za-z0-9_\-+/]{1,8000}={0,2}\z~';

    public function __construct(private PlatformSettings $platform) {}

    public function request(Request $request): JsonResponse|Response
    {
        $signed = Cast::string($request->input('signed_request'));
        if (preg_match(self::SHAPE, $signed) !== 1) {
            return response('', 400);
        }

        $userId = $this->verifiedUserId($signed);
        if ($userId === null) {
            return response('', 400);
        }

        // Keyed on the payload part: the signature part has several accepted spellings (padding, either
        // base64 alphabet) for the same bytes, while the payload is the exact string the HMAC covers.
        $seenKey = self::SEEN_PREFIX.hash('sha256', substr($signed, (int) strpos($signed, '.') + 1));
        $seen = Cache::get($seenKey);
        if (is_array($seen)) {
            return response()->json($seen);
        }

        $removed = Cast::int(SocialIdentity::query()->where('provider', 'facebook')->where('sub', $userId)->delete());
        Audit::record('auth.social.facebook_deletion', [
            'site_id' => null,
            'meta' => ['provider' => 'facebook', 'links_removed' => $removed],
        ]);

        $code = Str::lower(Str::random(32));
        Cache::put(self::CACHE_PREFIX.$code, ['done_at' => now()->toIso8601String()], now()->addDays(self::KEEP_DAYS));

        $answer = [
            'url' => route('oauth.facebook.deletion.status', ['code' => $code]),
            'confirmation_code' => $code,
        ];
        Cache::put($seenKey, $answer, self::SEEN_KEEP_SECONDS);

        return response()->json($answer);
    }

    public function status(string $code): Response
    {
        abort_unless(Cache::has(self::CACHE_PREFIX.$code), 404);

        return response()->view('admin::errors.page', [
            'page' => AdminErrorPages::facebookDataDeletedPage($code),
            'chrome' => [],
            'brandTokens' => AdminBrandColor::cssVariables(config('admin.brand.color')),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /** The Facebook user id the request was signed for, or null when the request is not genuine or not recent. */
    private function verifiedUserId(string $signed): ?string
    {
        $secret = SecretCipher::decrypt(Cast::string($this->platform->get('oauth_social', 'facebook')['client_secret'] ?? null));
        $parts = explode('.', $signed, 2);
        if ($secret === '' || count($parts) !== 2) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = $parts;
        $signature = self::base64UrlDecode($encodedSignature);
        $payload = json_decode((string) self::base64UrlDecode($encodedPayload), true);
        if ($signature === null || ! is_array($payload)) {
            return null;
        }

        if (strtoupper(Cast::string($payload['algorithm'] ?? null)) !== 'HMAC-SHA256'
            || ! hash_equals(hash_hmac('sha256', $encodedPayload, $secret, true), $signature)) {
            return null;
        }

        $issuedAt = $payload['issued_at'] ?? null;
        $now = now()->getTimestamp();
        if (! is_int($issuedAt) || $issuedAt > $now + self::MAX_AHEAD_SECONDS || $issuedAt < $now - self::MAX_AGE_SECONDS) {
            return null;
        }

        $userId = $payload['user_id'] ?? null;
        $userId = is_int($userId) ? (string) $userId : $userId;

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
