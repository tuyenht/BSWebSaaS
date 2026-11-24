<?php

use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SocialAuth;
use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Which social-login app a website's sign-in uses: the website's own app when it chose one and
 * saved both keys, else the platform's shared app when that is complete, switched on and not found
 * broken; and the website's own switch decides whether the button shows at all. Rows saved before
 * the switch existed keep working.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate platform_settings, social_identities restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
});

// The shared apps written here must not reach the next file's tests (platform_settings is never rolled back).
afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
});

/**
 * A settings row as written, with '@secret:<plain>' standing for an encrypted secret (datasets are
 * built before the settings key is configured).
 *
 * @param  array<string, mixed>  $row
 * @return array<string, mixed>
 */
function socialRow(array $row): array
{
    foreach ($row as $field => $value) {
        if (is_string($value) && str_starts_with($value, '@secret:')) {
            $row[$field] = SecretCipher::encrypt(substr($value, 8));
        }
    }

    return $row;
}

/** @param  array<string, mixed>|null  $site  @param  array<string, mixed>|null  $platform */
function seedSocialRows(?array $site, ?array $platform, string $provider = 'google'): void
{
    if ($platform !== null) {
        app(PlatformSettings::class)->put('oauth_social', $provider, socialRow($platform));
    }
    if ($site !== null) {
        app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('oauth_social', $provider, socialRow($site)));
    }
}

const SOCIAL_SHARED_READY = ['client_id' => 'shared-id', 'client_secret' => '@secret:shared-secret', 'enabled' => true, 'status' => 'ok', 'checked_at' => null];

it('picks the app and the switch for each website and platform row', function (?array $site, ?array $platform, bool $enabled, ?string $clientId): void {
    seedSocialRows($site, $platform);

    [$isEnabled, $cfg] = app(Tenancy::class)->enter(1, fn (): array => [
        app(SocialAuth::class)->enabled('google'),
        app(SocialAuth::class)->config('google'),
    ]);

    expect($isEnabled)->toBe($enabled)
        ->and($cfg['client_id'] ?? null)->toBe($clientId);
})->with([
    // Rows from before the switch: {client_id, client_secret, enabled}.
    'old own app, on' => [['client_id' => 'own-id', 'client_secret' => '@secret:own', 'enabled' => true], null, true, 'own-id'],
    'old own app, off' => [['client_id' => 'own-id', 'client_secret' => '@secret:own', 'enabled' => false], SOCIAL_SHARED_READY, false, 'own-id'],
    'old own app, no enabled key' => [['client_id' => 'own-id', 'client_secret' => '@secret:own'], null, true, 'own-id'],
    'old own app wins over the shared one' => [['client_id' => 'own-id', 'client_secret' => '@secret:own', 'enabled' => true], SOCIAL_SHARED_READY, true, 'own-id'],
    'old empty row unticked is not a switch-off' => [['client_id' => '', 'client_secret' => '', 'enabled' => false], SOCIAL_SHARED_READY, true, 'shared-id'],
    'old empty row, no shared app' => [['client_id' => '', 'client_secret' => '', 'enabled' => false], null, false, null],
    'old own id without a secret falls back to the shared app' => [['client_id' => 'own-id', 'client_secret' => '', 'enabled' => true], SOCIAL_SHARED_READY, true, 'shared-id'],
    // The switch and the own-app choice.
    'no row, shared ready' => [null, SOCIAL_SHARED_READY, true, 'shared-id'],
    'no row, no shared app' => [null, null, false, null],
    'switched off' => [['on' => false, 'own' => false], SOCIAL_SHARED_READY, false, 'shared-id'],
    'on, shared app' => [['on' => true, 'own' => false], SOCIAL_SHARED_READY, true, 'shared-id'],
    'own off ignores leftover own keys' => [['on' => true, 'own' => false, 'client_id' => 'own-id', 'client_secret' => '@secret:own'], SOCIAL_SHARED_READY, true, 'shared-id'],
    'own app wins over the shared one' => [['on' => true, 'own' => true, 'client_id' => 'own-id', 'client_secret' => '@secret:own'], SOCIAL_SHARED_READY, true, 'own-id'],
    'own app chosen but not saved yet' => [['on' => true, 'own' => true, 'client_id' => '', 'client_secret' => ''], SOCIAL_SHARED_READY, true, 'shared-id'],
    'own app chosen, nothing to fall back to' => [['on' => true, 'own' => true, 'client_id' => 'own-id', 'client_secret' => ''], null, false, null],
    // The shared app's own state.
    'shared app without a secret' => [null, ['client_id' => 'shared-id', 'client_secret' => ''], false, null],
    'shared app switched off by the platform' => [null, [...SOCIAL_SHARED_READY, 'enabled' => false], false, null],
    'shared app found broken' => [null, [...SOCIAL_SHARED_READY, 'status' => 'error'], false, null],
    'shared app never checked' => [null, ['client_id' => 'shared-id', 'client_secret' => '@secret:shared-secret'], true, 'shared-id'],
    'broken shared app does not hide an own app' => [['on' => true, 'own' => true, 'client_id' => 'own-id', 'client_secret' => '@secret:own'], [...SOCIAL_SHARED_READY, 'status' => 'error'], true, 'own-id'],
]);

it('reports the switch, the own-app choice and readiness separately', function (): void {
    seedSocialRows(['on' => false, 'own' => true, 'client_id' => 'own-id', 'client_secret' => '@secret:own'], [...SOCIAL_SHARED_READY, 'status' => 'error']);

    $flags = app(Tenancy::class)->enter(1, fn (): array => [
        app(SocialAuth::class)->siteOn('google'),
        app(SocialAuth::class)->usesOwn('google'),
        app(SocialAuth::class)->ownReady('google'),
        app(SocialAuth::class)->sharedReady('google'),
        app(SocialAuth::class)->clientSecret('google'),
    ]);

    expect($flags)->toBe([false, true, true, false, 'own']);
});

it('keeps one website\'s choice to itself', function (): void {
    seedSocialRows(['on' => false, 'own' => false], SOCIAL_SHARED_READY);

    expect(app(Tenancy::class)->enter(1, fn (): bool => app(SocialAuth::class)->enabled('google')))->toBeFalse()
        ->and(app(Tenancy::class)->enter(2, fn (): bool => app(SocialAuth::class)->enabled('google')))->toBeTrue();
});

it('reads only the platform tier on a host with no website, without touching tenant settings', function (): void {
    seedSocialRows(['on' => true, 'own' => true, 'client_id' => 'own-id', 'client_secret' => '@secret:own'], SOCIAL_SHARED_READY);

    $tables = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$tables): void {
        $tables[] = $query->sql;
    });

    $social = app(SocialAuth::class);
    expect($social->enabled('google'))->toBeTrue()
        ->and($social->config('google')['client_id'] ?? null)->toBe('shared-id')
        ->and($social->siteOn('google'))->toBeTrue()
        ->and($social->usesOwn('google'))->toBeFalse()
        ->and($social->enabled('facebook'))->toBeFalse()
        ->and(collect($tables)->filter(fn (string $sql): bool => str_contains($sql, 'site_settings'))->all())->toBe([]);
});

it('counts the accounts linked to each provider', function (): void {
    foreach (['facebook', 'facebook', 'google'] as $i => $provider) {
        SocialIdentity::query()->create(['user_id' => User::factory()->create()->getKey(), 'provider' => $provider, 'sub' => 'sub-'.$i]);
    }

    expect(app(SocialAuth::class)->usersCount('facebook'))->toBe(2)
        ->and(app(SocialAuth::class)->usersCount('google'))->toBe(1)
        ->and(app(SocialAuth::class)->usersCount('microsoft'))->toBe(0);
});

it('draws only the sign-in buttons this website can use', function (?array $google, ?array $facebook, bool $seesGoogle, bool $seesFacebook): void {
    seedSocialRows($google, SOCIAL_SHARED_READY, 'google');
    seedSocialRows($facebook, SOCIAL_SHARED_READY, 'facebook');

    $page = $this->get('http://a.test/admin/login')->assertOk();

    $seesGoogle ? $page->assertSee('oauth/google/start', false) : $page->assertDontSee('oauth/google/start', false);
    $seesFacebook ? $page->assertSee('oauth/facebook/start', false) : $page->assertDontSee('oauth/facebook/start', false);
    $seesGoogle || $seesFacebook ? $page->assertSee('auth-divider', false) : $page->assertDontSee('auth-divider', false);
    $page->assertDontSee('aria-disabled="true" tabindex="-1"', false);
})->with([
    'both on' => [null, null, true, true],
    'google off' => [['on' => false], null, false, true],
    'both off: no divider' => [['on' => false], ['on' => false], false, false],
]);

it('draws no sign-in buttons and no divider when no app is ready', function (): void {
    $this->get('http://a.test/admin/login')
        ->assertOk()
        ->assertDontSee('auth-social-btn', false)
        ->assertDontSee('auth-divider', false);
});

it('starts a sign-in with the shared app when the website has made no choice', function (): void {
    seedSocialRows(null, SOCIAL_SHARED_READY);

    $this->get('http://a.test/admin/oauth/google/start?purpose=login')
        ->assertRedirectContains('accounts.google.com')
        ->assertRedirectContains('client_id=shared-id');
});

it('refuses to start a sign-in the website switched off, even with a working shared app', function (): void {
    seedSocialRows(['on' => false, 'own' => false], SOCIAL_SHARED_READY);

    $this->get('http://a.test/admin/oauth/google/start?purpose=login')
        ->assertRedirectContains('/admin/login')
        ->assertSessionHasErrors('oauth');
});
