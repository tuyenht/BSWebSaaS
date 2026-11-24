<?php

use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;

/*
 * Meta's data-deletion callback for the platform's shared Facebook app: a request signed with the
 * app secret deletes that person's Facebook link and nothing else, and answers with a confirmation
 * code and the address of a page that shows it. Anything not signed right gets a bare 400.
 */

beforeEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate platform_settings, social_identities restart identity cascade');
    DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'auth.social.facebook_deletion')->delete();
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

/** A signed request in standard base64 with padding ('+', '/', '='), for one whose signature has a '+' or '/'. */
function fbSignedRequestStandardBase64(): string
{
    for ($i = 1; ; $i++) {
        $encoded = base64_encode((string) json_encode(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => '1234567890', 'n' => $i]));
        $signature = base64_encode(hash_hmac('sha256', $encoded, 'fb-app-secret', true));
        if (strpbrk($signature, '+/') !== false && str_ends_with($signature, '=')) {
            return $signature.'.'.$encoded;
        }
    }
}

/** @return list<object> */
function fbDeletionAudits(): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'auth.social.facebook_deletion')->get()->all();
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

    $audits = fbDeletionAudits();
    expect($audits)->toHaveCount(1)
        ->and(json_decode((string) $audits[0]->meta, true))->toBe(['provider' => 'facebook', 'links_removed' => 1])
        ->and(json_encode($audits[0]))->not->toContain('1234567890');
});

it('takes a numeric user id and the padded standard-base64 spelling', function (Closure $signed): void {
    $link = fbLink('facebook', '1234567890');

    $this->post('http://platform.bswebsaas.test/oauth/facebook/data-deletion', ['signed_request' => $signed()])
        ->assertOk()->assertJsonStructure(['url', 'confirmation_code']);

    expect(SocialIdentity::query()->whereKey($link->getKey())->exists())->toBeFalse();
})->with([
    'numeric user id' => [fn (): string => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => 1234567890])],
    'standard base64 with padding' => [fn (): string => fbSignedRequestStandardBase64()],
]);

it('confirms a request for a person with no link', function (): void {
    $this->post('http://platform.bswebsaas.test/oauth/facebook/data-deletion', [
        'signed_request' => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => '42']),
    ])->assertOk()->assertJsonStructure(['url', 'confirmation_code']);

    expect(json_decode((string) (fbDeletionAudits()[0]->meta ?? ''), true))->toBe(['provider' => 'facebook', 'links_removed' => 0]);
});

it('refuses a request not signed right, saying nothing about why', function (Closure $signed): void {
    $link = fbLink('facebook', '1234567890');

    $response = $this->post('http://platform.bswebsaas.test/oauth/facebook/data-deletion', ['signed_request' => $signed()]);

    $response->assertStatus(400);
    expect($response->getContent())->toBe('')
        ->and(SocialIdentity::query()->whereKey($link->getKey())->exists())->toBeTrue()
        ->and(fbDeletionAudits())->toBe([]);
})->with([
    'wrong secret' => [fn (): string => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => '1234567890'], 'other-secret')],
    'other algorithm' => [fn (): string => fbSignedRequest(['algorithm' => 'HMAC-SHA1', 'issued_at' => time(), 'user_id' => '1234567890'])],
    'no algorithm' => [fn (): string => fbSignedRequest(['issued_at' => time(), 'user_id' => '1234567890'])],
    'no user' => [fn (): string => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time()])],
    'payload changed after signing' => [function (): string {
        [$signature] = explode('.', fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => '999']));

        return $signature.'.'.fbBase64Url((string) json_encode(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => '1234567890']));
    }],
    'no issued_at' => [fn (): string => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'user_id' => '1234567890'])],
    'issued_at not a whole number' => [fn (): string => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => (string) time(), 'user_id' => '1234567890'])],
    'issued more than a day ago' => [fn (): string => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time() - 86401, 'user_id' => '1234567890'])],
    'issued more than five minutes ahead' => [fn (): string => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time() + 301, 'user_id' => '1234567890'])],
    'no dot' => [fn (): string => 'garbage'],
    'empty' => [fn (): string => ''],
]);

it('refuses every request while the shared app has no secret', function (): void {
    app(PlatformSettings::class)->put('oauth_social', 'facebook', ['client_id' => 'fb-app', 'client_secret' => '']);
    $link = fbLink('facebook', '1234567890');

    $this->post('http://platform.bswebsaas.test/oauth/facebook/data-deletion', [
        'signed_request' => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => '1234567890'], ''),
    ])->assertStatus(400);

    expect(SocialIdentity::query()->whereKey($link->getKey())->exists())->toBeTrue();
});

it('shows no status page for a code it never gave', function (): void {
    $this->get('http://platform.bswebsaas.test/oauth/facebook/data-deletion/'.str_repeat('a', 32))->assertNotFound();
});

it('takes a request a little ahead of this clock or almost a day old', function (int $offset): void {
    $link = fbLink('facebook', '1234567890');

    $this->post('http://platform.bswebsaas.test/oauth/facebook/data-deletion', [
        'signed_request' => fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time() + $offset, 'user_id' => '1234567890']),
    ])->assertOk();

    expect(SocialIdentity::query()->whereKey($link->getKey())->exists())->toBeFalse();
})->with(['four minutes ahead' => [240], 'almost a day old' => [-86000]]);

it('answers the same signed request sent again with the first answer and deletes nothing more', function (): void {
    fbLink('facebook', '1234567890');
    $signed = fbSignedRequest(['algorithm' => 'HMAC-SHA256', 'issued_at' => time(), 'user_id' => '1234567890']);
    $url = 'http://platform.bswebsaas.test/oauth/facebook/data-deletion';

    $first = $this->post($url, ['signed_request' => $signed])->assertOk();
    // The person signs in with Facebook again; a replay of the old request must not remove the new link.
    $relinked = fbLink('facebook', '1234567890');
    $again = $this->post($url, ['signed_request' => $signed])->assertOk();

    expect($again->json())->toBe($first->json())
        ->and(SocialIdentity::query()->whereKey($relinked->getKey())->exists())->toBeTrue()
        ->and(fbDeletionAudits())->toHaveCount(1);
});

it('refuses a body not shaped like a signed request before reading any setting', function (string $body): void {
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->post('http://platform.bswebsaas.test/oauth/facebook/data-deletion', ['signed_request' => $body])->assertStatus(400);

    expect(implode("\n", $queries))->not->toContain('platform_settings');
})->with([
    'no dot' => ['garbage'],
    'empty' => [''],
    'short signature' => ['abc.'.fbBase64Url('{"algorithm":"HMAC-SHA256"}')],
    'three parts' => [str_repeat('a', 43).'.'.fbBase64Url('{}').'.x'],
    'not base64' => [str_repeat('a', 43).'.{"user_id":"1"}'],
    'too long' => [str_repeat('a', 43).'.'.str_repeat('b', 8001)],
]);

it('takes the request without a session token and limits how often it is called', function (): void {
    $route = app('router')->getRoutes()->getByName('oauth.facebook.deletion');
    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:oauth-facebook-deletion')
        ->and(app('router')->gatherRouteMiddleware($route))->not->toContain(StartSession::class)
        ->and(app('router')->getRoutes()->getByName('oauth.facebook.deletion.status')?->gatherMiddleware())->toContain('throttle:oauth-facebook-deletion');

    // Meta's servers post it bare: the CSRF check must leave this one address alone (the test
    // runner skips that check entirely, so ask the middleware itself).
    $csrf = app(ValidateCsrfToken::class);
    $exempt = fn (string $url): bool => (fn (): bool => $this->inExceptArray(Request::create($url, 'POST')))->call($csrf);
    expect($exempt('http://platform.bswebsaas.test/oauth/facebook/data-deletion'))->toBeTrue()
        ->and($exempt('http://platform.bswebsaas.test/oauth/facebook/data-deletion/'.str_repeat('a', 32)))->toBeFalse()
        ->and($exempt('http://platform.bswebsaas.test/oauth/callback'))->toBeFalse();

    // No session, no cookie; thirty a minute from one address, then 429.
    $url = 'http://platform.bswebsaas.test/oauth/facebook/data-deletion';
    $first = $this->post($url, ['signed_request' => 'garbage']);
    expect($first->headers->getCookies())->toBe([]);
    for ($i = 2; $i <= 30; $i++) {
        $this->post($url, ['signed_request' => 'garbage'])->assertStatus(400);
    }
    $this->post($url, ['signed_request' => 'garbage'])->assertStatus(429);
});
