<?php

use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * Meta's data-deletion callback for the platform's shared Facebook app: a request signed with the
 * app secret deletes that person's Facebook link and nothing else, and answers with a confirmation
 * code and the address of a page that shows it. Anything not signed right gets a bare 400.
 */

beforeEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate platform_settings, social_identities restart identity cascade');
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    app(PlatformSettings::class)->put('oauth_social', 'facebook', [
        'client_id' => 'fb-app', 'client_secret' => SecretCipher::encrypt('fb-app-secret'), 'enabled' => true,
    ]);
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
});

function fbBase64Url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** @param  array<string, mixed>  $payload */
function fbSignedRequest(array $payload, string $secret = 'fb-app-secret'): string
{
    $encoded = fbBase64Url((string) json_encode($payload));

    return fbBase64Url(hash_hmac('sha256', $encoded, $secret, true)).'.'.$encoded;
}

function fbLink(string $provider, string $sub): SocialIdentity
{
    return SocialIdentity::query()->create(['user_id' => User::factory()->create()->getKey(), 'provider' => $provider, 'sub' => $sub]);
}

it('deletes only that person\'s Facebook link and answers with a confirmation', function (): void {
    $target = fbLink('facebook', '1234567890');
    $other = fbLink('facebook', '999');
    $sameSubOtherProvider = fbLink('google', '1234567890');

    $response = $this->post('http://platform.bswebsaas.test/oauth/facebook/data-deletion', [
        'signed_request' => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => '1234567890']),
    ]);

    $response->assertOk()->assertJsonStructure(['url', 'confirmation_code']);
    $code = $response->json('confirmation_code');
    expect($code)->toMatch('/^[a-z0-9]{32}$/')
        ->and($response->json('url'))->toEndWith('/oauth/facebook/data-deletion/'.$code)
        ->and(SocialIdentity::query()->whereKey($target->getKey())->exists())->toBeFalse()
        ->and(SocialIdentity::query()->whereKey($other->getKey())->exists())->toBeTrue()
        ->and(SocialIdentity::query()->whereKey($sameSubOtherProvider->getKey())->exists())->toBeTrue();

    $this->get((string) $response->json('url'))
        ->assertOk()
        ->assertSee(__('admin.oauth_fb_deletion_title'))
        ->assertSee($code);
});

it('confirms a request for a person with no link', function (): void {
    $this->post('http://platform.bswebsaas.test/oauth/facebook/data-deletion', [
        'signed_request' => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '42']),
    ])->assertOk()->assertJsonStructure(['url', 'confirmation_code']);
});

it('refuses a request not signed right, saying nothing about why', function (Closure $signed): void {
    $link = fbLink('facebook', '1234567890');

    $response = $this->post('http://platform.bswebsaas.test/oauth/facebook/data-deletion', ['signed_request' => $signed()]);

    $response->assertStatus(400);
    expect($response->getContent())->toBe('')
        ->and(SocialIdentity::query()->whereKey($link->getKey())->exists())->toBeTrue();
})->with([
    'wrong secret' => [fn (): string => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '1234567890'], 'other-secret')],
    'other algorithm' => [fn (): string => fbSignedRequest(['algorithm' => 'HMAC-SHA1', 'user_id' => '1234567890'])],
    'no algorithm' => [fn (): string => fbSignedRequest(['user_id' => '1234567890'])],
    'no user' => [fn (): string => fbSignedRequest(['algorithm' => 'HMAC-SHA256'])],
    'payload changed after signing' => [function (): string {
        [$signature] = explode('.', fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '999']));

        return $signature.'.'.fbBase64Url((string) json_encode(['algorithm' => 'HMAC-SHA256', 'user_id' => '1234567890']));
    }],
    'no dot' => [fn (): string => 'garbage'],
    'empty' => [fn (): string => ''],
]);

it('refuses every request while the shared app has no secret', function (): void {
    app(PlatformSettings::class)->put('oauth_social', 'facebook', ['client_id' => 'fb-app', 'client_secret' => '']);
    $link = fbLink('facebook', '1234567890');

    $this->post('http://platform.bswebsaas.test/oauth/facebook/data-deletion', [
        'signed_request' => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '1234567890'], ''),
    ])->assertStatus(400);

    expect(SocialIdentity::query()->whereKey($link->getKey())->exists())->toBeTrue();
});

it('shows no status page for a code it never gave', function (): void {
    $this->get('http://platform.bswebsaas.test/oauth/facebook/data-deletion/'.str_repeat('a', 32))->assertNotFound();
});

it('takes the request without a session token and limits how often it is called', function (): void {
    $route = app('router')->getRoutes()->getByName('oauth.facebook.deletion');
    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:60,1')
        ->and(app('router')->getRoutes()->getByName('oauth.facebook.deletion.status')?->gatherMiddleware())->toContain('throttle:60,1');

    // Meta's servers post it bare: the CSRF check must leave this one address alone (the test
    // runner skips that check entirely, so ask the middleware itself).
    $csrf = app(ValidateCsrfToken::class);
    $exempt = fn (string $url): bool => (fn (): bool => $this->inExceptArray(Request::create($url, 'POST')))->call($csrf);
    expect($exempt('http://platform.bswebsaas.test/oauth/facebook/data-deletion'))->toBeTrue()
        ->and($exempt('http://platform.bswebsaas.test/oauth/callback'))->toBeFalse();
});
