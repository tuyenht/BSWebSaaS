<?php

use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Cache;

/*
 * Centralized OAuth2 — social login + email-sender connect. ONE canonical redirect URI is
 * registered with each provider; start() stashes a single-use, PKCE-bound state and sends
 * the user to the provider; the central callback resolves the origin from that state.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
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
    $this->get('http://a.test/oauth/callback?state=bogus&code=whatever')
        ->assertRedirectContains('/admin/login')
        ->assertSessionHasErrors('oauth');
});

// ── finish() browser-binding (login-CSRF / session-fixation) ──────────────────────
// The single-use login token alone is not enough: finish() also requires the HttpOnly nonce
// cookie set on the initiating browser at start(). A captured/replayed/forged token completing
// into a different browser has no matching cookie and is refused.

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
    Cache::put('oauth-login:tok-ok', ['uid' => $user->getKey(), 'nonce' => hash('sha256', $nonce)], 120);

    $this->withCookie('oauth_login_nonce', $nonce)
        ->get('http://a.test/admin/oauth/finish?token=tok-ok')
        ->assertRedirect('http://a.test/admin');

    $this->assertAuthenticatedAs($user->fresh());
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
