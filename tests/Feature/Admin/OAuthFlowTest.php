<?php

use App\Modules\Admin\Support\OAuthState;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/*
 * Centralized OAuth2 — social login + email-sender connect. ONE canonical redirect URI is
 * registered with each provider; start() stashes a single-use, PKCE-bound state and sends
 * the user to the provider; the central callback resolves the origin from that state.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    // The platform's shared sign-in apps live in platform_settings, which nothing rolls back.
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('redirects social-login start to the tenant login when the provider is not configured', function (): void {
    $this->get('http://a.test/admin/oauth/google/start?purpose=login')
        ->assertRedirectContains('/admin/login')
        ->assertSessionHasErrors('oauth');
});

it('requires authentication to start an email-sender connect', function (): void {
    $this->get('http://a.test/admin/oauth/google/start?purpose=email')
        ->assertRedirectContains('/admin/login');
});

it('requires authentication to start a social account connect', function (): void {
    $this->get('http://a.test/admin/oauth/google/start?purpose=connect')
        ->assertRedirectContains('/admin/login');
});

it('sends a configured social-login start to the provider with a PKCE-bound state', function (): void {
    // SecretCipher needs a dedicated key; the test env does not set one.
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);

    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('oauth_social', 'google', [
        'client_id' => 'test-client-id',
        'client_secret' => SecretCipher::encrypt('test-secret'),
        'enabled' => true,
    ]));

    $this->get('http://a.test/admin/oauth/google/start?purpose=login')
        ->assertRedirectContains('accounts.google.com/o/oauth2/v2/auth')
        ->assertRedirectContains('client_id=test-client-id')
        ->assertRedirectContains('code_challenge=')
        ->assertRedirectContains('code_challenge_method=S256')
        ->assertRedirectContains('access_type=offline');
});

it('rejects an OAuth callback carrying an unknown state', function (): void {
    $location = (string) $this->get('http://a.test/oauth/callback?state=bogus&code=whatever')->headers->get('Location');

    expect($location)->toBe(consoleSignIn('state_mismatch'));
});

// ── finish() browser-binding (login-CSRF / session-fixation) ──────────────────────
// The single-use login token alone is not enough: finish() also requires the HttpOnly nonce
// cookie set on the initiating browser at start(). A captured/replayed/forged token completing
// into a different browser has no matching cookie and is refused.

it('never sends the browser back to an origin that is not a verified domain of a site', function (string $origin): void {
    DB::connection('pgsql_admin')->table('domains')->insert([
        'site_id' => 1, 'hostname' => 'waiting.example', 'kind' => 'custom', 'is_primary' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Cache::put('oauth-state:the-state', [
        'origin' => $origin, 'provider' => 'google', 'purpose' => 'login',
        'verifier' => 'v', 'user_id' => null, 'nonce' => 'n',
    ], 600);

    $location = (string) $this->get('http://a.test/oauth/callback?code=the-code&state=the-state')->headers->get('Location');

    expect($location)->toBe(consoleSignIn('failed'))
        ->not->toContain((string) parse_url($origin, PHP_URL_HOST));
})->with([
    'attached but not verified' => ['https://waiting.example'],
    'not attached at all' => ['https://evil.example'],
]);

it('sets a browser-bound nonce cookie when starting social login', function (): void {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);

    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('oauth_social', 'google', [
        'client_id' => 'test-client-id',
        'client_secret' => SecretCipher::encrypt('test-secret'),
        'enabled' => true,
    ]));

    $response = $this->get('http://a.test/admin/oauth/google/start?purpose=login')
        ->assertRedirectContains('accounts.google.com')
        ->assertCookie('oauth_login_nonce');

    // The whole browser-binding defense rests on SameSite=lax (so the cookie survives the cross-site
    // top-level GET back from the callback domain) + HttpOnly. Pin both so a later edit that flips them
    // to 'strict' / non-HttpOnly cannot pass CI while silently breaking real logins in prod.
    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c): bool => $c->getName() === 'oauth_login_nonce');
    expect($cookie)->not->toBeNull()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->isHttpOnly())->toBeTrue();
});

it('completes oauth finish when the browser nonce matches the login token', function (): void {
    $user = User::factory()->create();
    $nonce = 'the-initiating-browser-nonce';
    Cache::put('oauth-login:tok-ok', ['uid' => $user->getKey(), 'nonce' => hash('sha256', $nonce), 'trusted' => true], 120);

    $this->withCookie('oauth_login_nonce', $nonce)
        ->get('http://a.test/admin/oauth/finish?token=tok-ok')
        ->assertRedirect('http://a.test/admin');

    $this->assertAuthenticatedAs($user->fresh());
});

it('asks a login token that does not vouch for the mailbox for the emailed code', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $nonce = 'the-initiating-browser-nonce';
    // e.g. a token written before this rule: no 'trusted' flag at all means no shortcut.
    Cache::put('oauth-login:tok-plain', ['uid' => $user->getKey(), 'nonce' => hash('sha256', $nonce), 'provider' => 'facebook'], 120);

    $this->withCookie('oauth_login_nonce', $nonce)
        ->get('http://a.test/admin/oauth/finish?token=tok-plain')
        ->assertRedirect(route('admin.otp.challenge'));

    $this->assertGuest();
    expect(session('2fa.user_id'))->toBe($user->getKey());
});

it('refuses oauth finish when the browser presents no nonce cookie', function (): void {
    $user = User::factory()->create();
    Cache::put('oauth-login:tok-nocookie', ['uid' => $user->getKey(), 'nonce' => hash('sha256', 'real-nonce')], 120);

    // A replayed token in a browser that never began the flow — no nonce cookie.
    $this->get('http://a.test/admin/oauth/finish?token=tok-nocookie')
        ->assertRedirectContains('/admin/login');

    $this->assertGuest();
});

it('refuses oauth finish when the browser nonce does not match the token', function (): void {
    $user = User::factory()->create();
    Cache::put('oauth-login:tok-wrong', ['uid' => $user->getKey(), 'nonce' => hash('sha256', 'real-nonce')], 120);

    $this->withCookie('oauth_login_nonce', 'a-different-nonce')
        ->get('http://a.test/admin/oauth/finish?token=tok-wrong')
        ->assertRedirectContains('/admin/login');

    $this->assertGuest();
});

// ── email-sender connect is a site setting ──────────────────────────────────────────
// Connecting a mailbox changes how the whole website sends mail — sign-in codes, invitations —
// so it takes the same right as the Email settings screen (settings.manage on THIS site), checked
// when the flow starts and again when the provider answers. Signing in on a site's host does not
// require membership, so "signed in" alone would let any account hijack another site's sender.

/** Site $site has its own mail OAuth app, so a connect can start there. */
function configureEmailOAuth(int $site): void
{
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);

    app(Tenancy::class)->enter($site, fn () => app(SettingsRepository::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'oauth' => ['client_id' => 'mail-client', 'client_secret' => SecretCipher::encrypt('mail-secret')],
    ]));
}

/** @param  list<string>  $permissions */
function oauthMember(int $site, array $permissions): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'role-'.$user->getKey(), $permissions));
    app(TeamContext::class)->forget();

    return $user;
}

/** @return array<string, mixed> site $site's email settings */
function siteEmailConfig(int $site): array
{
    return app(Tenancy::class)->enter($site, fn (): array => app(SettingsRepository::class)->get('email') ?? []);
}

it('refuses an email-sender connect from a member who may not manage settings', function (): void {
    configureEmailOAuth(1);
    $viewer = oauthMember(1, ['entries.view']);

    $this->actingAs($viewer)->get('http://a.test/admin/oauth/google/start?purpose=email')
        ->assertForbidden();
});

it('refuses an email-sender connect from an account that is not a member of this site', function (): void {
    configureEmailOAuth(1);
    $otherSiteManager = oauthMember(2, ['settings.manage']);

    $this->actingAs($otherSiteManager)->get('http://a.test/admin/oauth/google/start?purpose=email')
        ->assertForbidden();
});

it('sends a settings manager to the provider to connect the site sender', function (): void {
    configureEmailOAuth(1);
    $manager = oauthMember(1, ['settings.manage']);

    $this->actingAs($manager)->get('http://a.test/admin/oauth/google/start?purpose=email')
        ->assertRedirectContains('accounts.google.com')
        ->assertRedirectContains('client_id=mail-client');
});

it('connects the sender only if the person still manages settings when the provider answers', function (bool $keepsRight): void {
    configureEmailOAuth(1);
    $manager = oauthMember(1, ['settings.manage']);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt']),
        'openidconnect.googleapis.com/*' => Http::response(['sub' => '42', 'email' => 'box@example.com']),
    ]);

    $location = (string) $this->actingAs($manager)->get('http://a.test/admin/oauth/google/start?purpose=email')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    if (! $keepsRight) {
        app(Tenancy::class)->enter(1, fn () => $manager->roles()->detach());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(TeamContext::class)->forget();
    }

    $this->get('http://a.test/oauth/callback?code=the-code&state='.$query['state'])
        ->assertRedirectContains('/admin/settings/email');

    $email = siteEmailConfig(1);
    expect($email['method'] ?? null)->toBe($keepsRight ? 'oauth2' : 'smtp_password')
        ->and(isset($email['oauth']['refresh_token']))->toBe($keepsRight);
})->with(['still a manager' => [true], 'right removed meanwhile' => [false]]);

// ── A callback whose single-use state is gone (expired, already used, never ours) ──────────────
// The person goes back to the sign-in page of the website they started from when the state value
// carries a valid signature for a verified domain of a site; anything else lands on the console's
// own sign-in page, never on a host taken from the address.

/** The console host's sign-in page with an OAuth reason code — the dead-end-free fallback. */
function consoleSignIn(string $code): string
{
    return 'http://'.config('tenancy.platform_host').'/login?oauth_error='.$code;
}

function configureSocialGoogle(int $site): void
{
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);

    app(Tenancy::class)->enter($site, fn () => app(SettingsRepository::class)->put('oauth_social', 'google', [
        'client_id' => 'test-client-id',
        'client_secret' => SecretCipher::encrypt('test-secret'),
        'enabled' => true,
    ]));
}

/** Starts a social sign-in on the given origin and returns the state sent to the provider. */
function startSocialLogin(object $test, string $origin): string
{
    $location = (string) $test->get($origin.'/admin/oauth/google/start?purpose=login')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

it('sends a person whose sign-in state expired back to the sign-in page of their website', function (): void {
    configureSocialGoogle(1);
    $state = startSocialLogin($this, 'http://a.test');
    Cache::forget('oauth-state:'.$state);

    $this->get('http://'.config('tenancy.platform_host').'/oauth/callback?code=the-code&state='.urlencode($state))
        ->assertRedirect('http://a.test/admin/login?oauth_error=state_mismatch');
});

it('keeps the state single-use: a second visit to the callback signs nobody in', function (): void {
    configureSocialGoogle(1);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']),
        'openidconnect.googleapis.com/*' => Http::response(['sub' => '42', 'email' => 'nobody@example.com', 'email_verified' => true]),
    ]);
    $state = startSocialLogin($this, 'http://a.test');
    $callback = 'http://'.config('tenancy.platform_host').'/oauth/callback?code=the-code&state='.urlencode($state);

    $this->get($callback)->assertRedirect('http://a.test/admin/login?oauth_error=no_account');
    Http::assertSentCount(2);

    $this->get($callback)->assertRedirect('http://a.test/admin/login?oauth_error=state_mismatch');
    Http::assertSentCount(2);
    expect(Cache::get('oauth-state:'.$state))->toBeNull();
});

it('never follows a state value that this app did not sign', function (string $state): void {
    $location = (string) $this->get('http://'.config('tenancy.platform_host').'/oauth/callback?code=the-code&state='.urlencode($state))
        ->headers->get('Location');

    // Every forged value names a host that IS a verified site domain: only the signature stops it.
    expect($location)->toBe(consoleSignIn('state_mismatch'))->not->toContain('b.test');
})->with([
    'garbage' => ['bogus'],
    'empty' => [''],
    'three empty parts' => ['..'],
    'signature changed' => [function (): string {
        [$random, $origin] = explode('.', OAuthState::mint('http://b.test'));

        return $random.'.'.$origin.'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }],
    'host swapped, signature kept' => [function (): string {
        [$random, , $signature] = explode('.', OAuthState::mint('http://a.test'));

        return $random.'.'.rtrim(strtr(base64_encode('http://b.test'), '+/', '-_'), '=').'.'.$signature;
    }],
    'signed with another key' => [function (): string {
        $random = 'r';
        $origin = rtrim(strtr(base64_encode('http://b.test'), '+/', '-_'), '=');

        return $random.'.'.$origin.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', 'oauth-state|'.$random.'|'.$origin, 'not-the-app-key', true)), '+/', '-_'), '=');
    }],
]);

it('lands on the console sign-in page for a signed state whose host is no verified site domain', function (string $origin): void {
    DB::connection('pgsql_admin')->table('domains')->insert([
        'site_id' => 1, 'hostname' => 'waiting.example', 'kind' => 'custom', 'is_primary' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $location = (string) $this->get('http://'.config('tenancy.platform_host').'/oauth/callback?state='.urlencode(OAuthState::mint($origin)))
        ->headers->get('Location');

    expect($location)->toBe(consoleSignIn('state_mismatch'))
        ->not->toContain((string) parse_url($origin, PHP_URL_HOST));
})->with([
    'attached but not verified' => ['https://waiting.example'],
    'not attached at all' => ['https://evil.example'],
]);

it('reads back only a bare origin from a signed state value', function (string $origin, ?string $expected): void {
    expect(OAuthState::origin(OAuthState::mint($origin)))->toBe($expected);
})->with([
    'plain host' => ['http://a.test', 'http://a.test'],
    'two padding chars' => ['https://ab.example', 'https://ab.example'],
    'with a port' => ['https://abc.example:8443', 'https://abc.example:8443'],
    'with a path' => ['http://a.test/admin', null],
    'another scheme' => ['javascript:alert(1)', null],
    'user info' => ['https://a.test@evil.example', null],
]);

it('reads nothing from a state value that is not a string or is too long', function (): void {
    expect(OAuthState::origin(['x']))->toBeNull()
        ->and(OAuthState::origin(null))->toBeNull()
        ->and(OAuthState::origin(str_repeat('a', 2000)))->toBeNull();
});

it('shows the expired-request sentence on the sign-in page it lands on', function (): void {
    app()->setLocale('vi');

    $this->get('http://a.test/admin/login?oauth_error=state_mismatch')
        ->assertOk()
        ->assertSee(__('admin.oauth_state_mismatch'));
    $this->get(consoleSignIn('state_mismatch'))
        ->assertOk()
        ->assertSee(__('admin.oauth_state_mismatch'));
});
