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
 * signature is its proof. A bad request gets a bare 400 that does not say what was wrong.
 */
final readonly class FacebookDataDeletionController
{
    private const string CACHE_PREFIX = 'oauth-fb-deletion:';

    private const int KEEP_DAYS = 90;

    public function __construct(private PlatformSettings $platform) {}

    public function request(Request $request): JsonResponse|Response
    {
        $userId = $this->verifiedUserId(Cast::string($request->input('signed_request')));
        if ($userId === null) {
            return response('', 400);
        }

        $removed = Cast::int(SocialIdentity::query()->where('provider', 'facebook')->where('sub', $userId)->delete());
        Audit::record('auth.social.facebook_deletion', [
            'site_id' => null,
            'meta' => ['provider' => 'facebook', 'links_removed' => $removed],
        ]);

        $code = Str::lower(Str::random(32));
        Cache::put(self::CACHE_PREFIX.$code, ['done_at' => now()->toIso8601String()], now()->addDays(self::KEEP_DAYS));

        return response()->json([
            'url' => route('oauth.facebook.deletion.status', ['code' => $code]),
            'confirmation_code' => $code,
        ]);
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

    /** The Facebook user id the request was signed for, or null when the request is not genuine. */
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
