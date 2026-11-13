<?php

use App\Modules\Admin\Support\OAuthError;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Http;

/*
 * A sign-in or connect that comes back from Google/Facebook without success tells the screen why
 * with a short code in the address. The screen prints its own sentence for a known code and nothing
 * for anything else, so a link cannot make the admin show words of a stranger's choosing.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function oauthSettingsManager(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'role-'.$user->getKey(), ['settings.manage']));
    app(TeamContext::class)->forget();

    return $user;
}

it('gives every reason a sentence of its own in each language', function (string $locale): void {
    app()->setLocale($locale);

    foreach (OAuthError::cases() as $case) {
        expect($case->message())->not->toStartWith('admin.')->not->toBe('');
    }
})->with(['vi', 'en']);

it('reads only known codes from the address', function (): void {
    expect(OAuthError::messageFor('not_member'))->toBe(__('admin.oauth_login_not_member'))
        ->and(OAuthError::messageFor('Tài khoản bị khoá, gọi 0900 000 000 để mở lại'))->toBeNull()
        ->and(OAuthError::messageFor(['failed']))->toBeNull()
        ->and(OAuthError::messageFor(null))->toBeNull();
});

it('shows the sentence for a known code and ignores planted words on the settings screens', function (string $path): void {
    $manager = oauthSettingsManager();

    $this->actingAs($manager)->get('http://a.test/admin/'.$path.'?oauth_error=no_refresh_token')
        ->assertOk()
        ->assertSee(__('admin.oauth_no_refresh_token'));

    $this->actingAs($manager)->get('http://a.test/admin/'.$path.'?oauth_error='.urlencode('Tài khoản bị khoá, gọi 0900 000 000 để mở lại'))
        ->assertOk()
        ->assertDontSee('Tài khoản bị khoá, gọi 0900 000 000 để mở lại')
        ->assertDontSee('0900 000 000');
})->with(['email sender' => ['settings/email'], 'social sign-in' => ['settings/social']]);

it('shows the sentence for a known code and ignores planted words on the linked accounts tab', function (): void {
    $manager = oauthSettingsManager();

    $this->actingAs($manager)->get('http://a.test/admin/account?tab=linked&oauth_error=already_linked')
        ->assertOk()
        ->assertSee(__('admin.social_already_linked'));

    $this->actingAs($manager)->get('http://a.test/admin/account?tab=linked&oauth_error='.urlencode('Tài khoản bị khoá, gọi 0900 000 000 để mở lại'))
        ->assertOk()
        ->assertDontSee('0900 000 000');
});

it('tells a visitor on the sign-in screen why a Google or Facebook sign-in did not finish', function (): void {
    $this->get('http://a.test/admin/login?oauth_error=not_member')
        ->assertOk()
        ->assertSee(__('admin.oauth_login_not_member'));

    $this->get('http://a.test/admin/login?oauth_error='.urlencode('Tài khoản bị khoá, gọi 0900 000 000 để mở lại'))
        ->assertOk()
        ->assertDontSee('0900 000 000');

    $this->followingRedirects()->get('http://a.test/admin/oauth/google/start?purpose=login')
        ->assertOk()
        ->assertSee(__('admin.oauth_provider_not_configured'));
});

it('sends the code, not a sentence, when the provider gives no lasting access to the mailbox', function (): void {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'oauth' => ['client_id' => 'mail-client', 'client_secret' => SecretCipher::encrypt('mail-secret')],
    ]));
    $manager = oauthSettingsManager();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']),
        'openidconnect.googleapis.com/*' => Http::response(['sub' => '42', 'email' => 'box@example.com']),
    ]);

    $location = (string) $this->actingAs($manager)->get('http://a.test/admin/oauth/google/start?purpose=email')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $back = (string) $this->get('http://a.test/oauth/callback?code=the-code&state='.$query['state'])->headers->get('Location');

    expect($back)->toContain('/admin/settings/email?oauth_error=no_refresh_token');
});

it('tells a member on the linked accounts tab when the provider is not set up', function (): void {
    $manager = oauthSettingsManager();

    $this->actingAs($manager)->followingRedirects()->get('http://a.test/admin/oauth/google/start?purpose=connect')
        ->assertOk()
        ->assertSee(__('admin.oauth_provider_not_configured'));
});
