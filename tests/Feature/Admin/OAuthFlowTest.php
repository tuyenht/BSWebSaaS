<?php

use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Centralized OAuth2 — social login + email-sender connect. ONE canonical redirect URI is
 * registered with each provider; start() stashes a single-use, PKCE-bound state and sends
 * the user to the provider; the central callback resolves the origin from that state.
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('redirects social-login start to the tenant login when the provider is not configured', function () {
    $this->get('http://a.test/admin/oauth/google/start?purpose=login')
        ->assertRedirectContains('/admin/login')
        ->assertSessionHasErrors('oauth');
});

it('requires authentication to start an email-sender connect', function () {
    $this->get('http://a.test/admin/oauth/google/start?purpose=email')
        ->assertRedirectContains('/admin/login');
});

it('requires authentication to start a social account connect', function () {
    $this->get('http://a.test/admin/oauth/google/start?purpose=connect')
        ->assertRedirectContains('/admin/login');
});

it('sends a configured social-login start to the provider with a PKCE-bound state', function () {
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

it('rejects an OAuth callback carrying an unknown state', function () {
    $this->get('http://a.test/oauth/callback?state=bogus&code=whatever')
        ->assertRedirectContains('/admin/login')
        ->assertSessionHasErrors('oauth');
});
