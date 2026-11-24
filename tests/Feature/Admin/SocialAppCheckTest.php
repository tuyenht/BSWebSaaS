<?php

use App\Modules\Admin\Support\OAuthFlow;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SocialAppCheck;
use App\Modules\Admin\Support\SocialAppVerdict;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * The live check of a social-login app's keys, and the six-hourly run that marks the platform's
 * shared apps: refused keys hide the button, a network fault changes nothing, the secret never
 * reaches a log.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
    DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'platform.oauth_social.status')->delete();
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
});

it('reads Google\'s answer to an impossible code', function (int $status, array $body, SocialAppVerdict $verdict): void {
    Http::fake(['oauth2.googleapis.com/*' => Http::response($body, $status)]);

    expect(app(SocialAppCheck::class)->check('google', 'the-id', 'the-secret'))->toBe($verdict);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['client_id'] === 'the-id'
        && $request['client_secret'] === 'the-secret'
        && $request['code'] === 'bs-check'
        && $request['grant_type'] === 'authorization_code'
        && $request['redirect_uri'] === OAuthFlow::callbackUrl());
})->with([
    'unknown client' => [401, ['error' => 'invalid_client', 'error_description' => 'The OAuth client was not found.'], SocialAppVerdict::Invalid],
    'client not allowed' => [400, ['error' => 'unauthorized_client'], SocialAppVerdict::Invalid],
    'keys right, code refused' => [400, ['error' => 'invalid_grant', 'error_description' => 'Malformed auth code.'], SocialAppVerdict::Valid],
    'other error' => [400, ['error' => 'invalid_request'], SocialAppVerdict::Unknown],
    'server fault' => [503, [], SocialAppVerdict::Unknown],
]);

it('reads Facebook\'s answer to an app token request', function (int $status, array $body, SocialAppVerdict $verdict): void {
    Http::fake(['graph.facebook.com/*' => Http::response($body, $status)]);

    expect(app(SocialAppCheck::class)->check('facebook', 'the-id', 'the-secret'))->toBe($verdict);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://graph.facebook.com/v19.0/oauth/access_token?')
        && $request['client_id'] === 'the-id'
        && $request['grant_type'] === 'client_credentials');
})->with([
    'token back' => [200, ['access_token' => 'app|token', 'token_type' => 'bearer'], SocialAppVerdict::Valid],
    'unknown app' => [400, ['error' => ['message' => 'Invalid Client ID', 'type' => 'OAuthException', 'code' => 101]], SocialAppVerdict::Invalid],
    'wrong secret' => [400, ['error' => ['message' => 'Error validating client secret.', 'type' => 'OAuthException', 'code' => 1]], SocialAppVerdict::Invalid],
    'unknown fault' => [500, ['error' => ['message' => 'An unknown error has occurred.', 'type' => 'OAuthException', 'code' => 1]], SocialAppVerdict::Unknown],
    'rate limited' => [400, ['error' => ['message' => 'Application request limit reached', 'code' => 4]], SocialAppVerdict::Unknown],
]);

it('calls a network fault Unknown and never logs the secret', function (string $provider): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://example.test/?client_secret=top-secret-value'));

    expect(app(SocialAppCheck::class)->check($provider, 'the-id', 'top-secret-value'))->toBe(SocialAppVerdict::Unknown)
        ->and($logged)->not->toBeEmpty()
        ->and(implode("\n", $logged))->not->toContain('top-secret-value');
})->with(['google', 'facebook']);

it('asks nothing for a provider it cannot check', function (): void {
    Http::fake();

    expect(app(SocialAppCheck::class)->check('microsoft', 'the-id', 'the-secret'))->toBe(SocialAppVerdict::Unknown);
    Http::assertNothingSent();
});

/** @param  array<string, mixed>  $extra */
function sharedSocialApp(string $provider, array $extra = []): void
{
    app(PlatformSettings::class)->put('oauth_social', $provider, [
        'client_id' => $provider.'-id',
        'client_secret' => SecretCipher::encrypt($provider.'-secret'),
        'enabled' => true,
        ...$extra,
    ]);
}

/** @return array<string, mixed> */
function sharedSocialRow(string $provider): array
{
    return app(PlatformSettings::class)->get('oauth_social', $provider) ?? [];
}

function socialStatusAudits(): int
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'platform.oauth_social.status')->count();
}

it('marks each shared app by the provider\'s answer', function (): void {
    sharedSocialApp('google', ['status' => null]);
    sharedSocialApp('facebook', ['status' => 'ok']);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid Client ID', 'code' => 101]], 400),
    ]);

    $this->artisan('social:check-apps')->assertSuccessful();

    $google = sharedSocialRow('google');
    $facebook = sharedSocialRow('facebook');
    expect($google['status'])->toBe('ok')
        ->and($google['checked_at'])->toBeString()->not->toBe('')
        ->and($google['client_id'])->toBe('google-id')
        ->and(SecretCipher::decrypt($google['client_secret']))->toBe('google-secret')
        ->and($facebook['status'])->toBe('error')
        ->and($facebook['checked_at'])->toBeString()
        ->and(socialStatusAudits())->toBe(2);
});

it('keeps the old status when the provider gives no clear answer', function (): void {
    sharedSocialApp('google', ['status' => 'ok', 'checked_at' => '2026-11-01T00:00:00+00:00']);
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $this->artisan('social:check-apps')->assertSuccessful();

    expect(sharedSocialRow('google'))->toMatchArray(['status' => 'ok', 'checked_at' => '2026-11-01T00:00:00+00:00'])
        ->and(socialStatusAudits())->toBe(0);
});

it('writes the check time but no audit row when the status stays the same', function (): void {
    sharedSocialApp('google', ['status' => 'ok', 'checked_at' => '2026-11-01T00:00:00+00:00']);
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->artisan('social:check-apps')->assertSuccessful();

    expect(sharedSocialRow('google')['status'])->toBe('ok')
        ->and(sharedSocialRow('google')['checked_at'])->not->toBe('2026-11-01T00:00:00+00:00')
        ->and(socialStatusAudits())->toBe(0);
});

it('stamps nothing on keys replaced while the provider answered', function (array $replacement): void {
    sharedSocialApp('google', ['status' => 'ok', 'checked_at' => '2026-11-01T00:00:00+00:00']);
    // The provider refuses the old keys; meanwhile someone saves new ones.
    Http::fake(function () use ($replacement) {
        app(PlatformSettings::class)->put('oauth_social', 'google', [
            'client_id' => $replacement['client_id'],
            'client_secret' => SecretCipher::encrypt($replacement['secret']),
            'enabled' => true,
        ]);

        return Http::response(['error' => 'invalid_client'], 401);
    });

    $this->artisan('social:check-apps')->assertSuccessful();

    $row = sharedSocialRow('google');
    expect($row['client_id'])->toBe($replacement['client_id'])
        ->and(SecretCipher::decrypt($row['client_secret']))->toBe($replacement['secret'])
        ->and($row)->not->toHaveKey('status')
        ->and($row)->not->toHaveKey('checked_at')
        ->and(socialStatusAudits())->toBe(0);
})->with([
    'new client id' => [['client_id' => 'new-id', 'secret' => 'new-secret']],
    'same id, new secret' => [['client_id' => 'google-id', 'secret' => 'new-secret']],
]);

it('brings a broken app back once its keys are accepted', function (): void {
    sharedSocialApp('facebook', ['status' => 'error']);
    Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'app|token'])]);

    $this->artisan('social:check-apps')->assertSuccessful();

    expect(sharedSocialRow('facebook')['status'])->toBe('ok')
        ->and(socialStatusAudits())->toBe(1);
});

it('checks only shared apps with both keys', function (): void {
    app(PlatformSettings::class)->put('oauth_social', 'google', ['client_id' => 'google-id', 'client_secret' => '', 'enabled' => true]);
    app(PlatformSettings::class)->put('oauth_social', 'facebook', ['client_id' => '', 'client_secret' => SecretCipher::encrypt('x')]);
    Http::fake();

    $this->artisan('social:check-apps')->assertSuccessful();

    Http::assertNothingSent();
    expect(sharedSocialRow('google'))->not->toHaveKey('status');
});

it('runs the check every six hours, never two at once', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'social:check-apps'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 */6 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
