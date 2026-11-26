<?php

use App\Modules\Admin\Livewire\EmailOtpChallenge;
use App\Modules\Admin\Livewire\SocialSettings;
use App\Modules\Admin\Notifications\EmailOtpNotification;
use App\Modules\Admin\Support\AuditEntryView;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SocialAuth;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\SocialIdentity;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Which social-login app a website's sign-in uses: the website's own app when it chose one and
 * saved both keys, else the platform's shared app when that is complete, switched on and not found
 * broken; and the website's own switch decides whether the button shows at all. Rows saved before
 * the switch existed keep working.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate platform_settings, social_identities, audit_logs restart identity cascade');
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

it('never hands one website\'s own app to another website', function (?array $platform, ?string $clientId, ?string $secret): void {
    seedSocialRows(['on' => true, 'own' => true, 'client_id' => 'site1-id', 'client_secret' => '@secret:site1-secret'], $platform);

    $social = app(SocialAuth::class);
    [$cfg, $plain, $ownReady] = app(Tenancy::class)->enter(2, fn (): array => [
        $social->config('google'), $social->clientSecret('google'), $social->ownReady('google'),
    ]);

    expect($cfg['client_id'] ?? null)->toBe($clientId)
        ->and($plain)->toBe($secret)
        ->and($ownReady)->toBeFalse()
        ->and(app(Tenancy::class)->enter(1, fn (): ?string => $social->clientSecret('google')))->toBe('site1-secret');
})->with([
    'shared app ready' => [SOCIAL_SHARED_READY, 'shared-id', 'shared-secret'],
    'no shared app' => [null, null, null],
]);

it('refuses the provider\'s answer when the app was switched off after the start', function (string $purpose, array $site, ?array $platform): void {
    seedSocialRows(null, SOCIAL_SHARED_READY);
    $user = User::factory()->create();
    Http::fake();

    $start = $purpose === 'connect' ? $this->actingAs($user) : $this;
    $location = (string) $start->get('http://a.test/admin/oauth/google/start?purpose='.$purpose)->headers->get('Location');
    expect($location)->toContain('accounts.google.com');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    // Switched off while the person was at the provider.
    seedSocialRows($site === [] ? null : $site, $platform);

    $this->get('http://a.test/oauth/callback?code=the-code&state='.$query['state'])
        ->assertRedirectContains($purpose === 'connect' ? '/admin/account?tab=linked&oauth_error=' : '/admin/login?oauth_error=');

    Http::assertNothingSent();
    expect(SocialIdentity::query()->count())->toBe(0);
})->with([
    'sign-in' => ['login'],
    'link' => ['connect'],
])->with([
    'website switch' => [['on' => false, 'own' => false], null],
    'platform switch' => [[], [...SOCIAL_SHARED_READY, 'enabled' => false]],
]);

function socialSiteAdmin(): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

it('turns the shared app off on a website when its switch is saved off, and on again when saved on', function (): void {
    seedSocialRows(null, SOCIAL_SHARED_READY);
    $admin = socialSiteAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SocialSettings::class)
            ->assertSet('on.google', true)
            ->call('toggle', 'google')->call('confirmAsk')
            ->call('save')->assertHasNoErrors();

        expect(app(SocialAuth::class)->enabled('google'))->toBeFalse()
            ->and(app(SocialAuth::class)->config('google')['client_id'] ?? null)->toBe('shared-id');
        Livewire::actingAs($admin)->test(SocialSettings::class)->assertSet('on.google', false);

        Livewire::actingAs($admin)->test(SocialSettings::class)
            ->call('toggle', 'google')
            ->call('save')->assertHasNoErrors();

        expect(app(SocialAuth::class)->enabled('google'))->toBeTrue();
    });
});

it('uses the website\'s own app once its keys are saved on the screen', function (): void {
    seedSocialRows(null, SOCIAL_SHARED_READY);
    $admin = socialSiteAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SocialSettings::class)
            ->call('useOwn', 'google', 'own-id.apps.googleusercontent.com', 'GOCSPX-own-secret')
            ->assertReturned(true);

        expect(app(SocialAuth::class)->enabled('google'))->toBeTrue()
            ->and(app(SocialAuth::class)->usesOwn('google'))->toBeTrue()
            ->and(app(SocialAuth::class)->config('google')['client_id'] ?? null)->toBe('own-id.apps.googleusercontent.com')
            ->and(app(SocialAuth::class)->clientSecret('google'))->toBe('GOCSPX-own-secret');
    });
});

it('shows the switch as it really is for a row saved before the switch existed', function (): void {
    seedSocialRows(['client_id' => '', 'client_secret' => '', 'enabled' => false], SOCIAL_SHARED_READY);
    $admin = socialSiteAdmin();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(SocialSettings::class)
        ->assertSet('on.google', true));
});

// ── what a Facebook sign-in tells the person, and what it leaves in the audit trail ─────────────
// Facebook never asserts a verified email, so a Facebook account only signs in once it is linked
// from «Tài khoản của tôi». Before that the person is told how to link it — "try again" never helps.

/** Fakes Facebook's token and profile answers for the given Facebook user id. */
function fakeFacebook(string $id, ?string $email = 'fb@example.com'): void
{
    Http::fake([
        'graph.facebook.com/v19.0/oauth/*' => Http::response(['access_token' => 'at']),
        'graph.facebook.com/me*' => Http::response(array_filter(['id' => $id, 'name' => 'Người dùng', 'email' => $email])),
    ]);
}

/** Runs a Facebook sign-in through the provider and returns [callback Location, browser nonce]. */
function facebookSignIn(object $test): array
{
    $start = $test->get('http://a.test/admin/oauth/facebook/start?purpose=login');
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
    $nonce = (string) $start->getCookie('oauth_login_nonce')?->getValue();

    $back = (string) $test->get('http://a.test/oauth/callback?code=the-code&state='.$query['state'])->headers->get('Location');

    return [$back, $nonce];
}

/** A member of website 1 (where a.test points). @param  array<string, mixed>  $attributes */
function quickLoginMember(array $attributes = []): User
{
    $member = User::factory()->create($attributes);
    app(Tenancy::class)->enter(1, fn () => grantRole($member, 'role-'.$member->getKey(), ['settings.manage']));
    app(TeamContext::class)->forget();

    return $member->fresh();
}

/** Fakes Google's token and profile answers: a verified email, as Google sends for its accounts. */
function fakeGoogle(string $sub, string $email): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']),
        'openidconnect.googleapis.com/*' => Http::response(['sub' => $sub, 'email' => $email, 'email_verified' => true]),
    ]);
}

/** Runs a Google sign-in through the provider and returns [callback Location, browser nonce]. */
function googleSignIn(object $test): array
{
    $start = $test->get('http://a.test/admin/oauth/google/start?purpose=login');
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
    $nonce = (string) $start->getCookie('oauth_login_nonce')?->getValue();

    return [(string) $test->get('http://a.test/oauth/callback?code=the-code&state='.$query['state'])->headers->get('Location'), $nonce];
}

function socialAuditRow(string $action): ?object
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', $action)->sole();
}

it('tells a Facebook account that is not linked yet how to link it, and links nothing', function (): void {
    seedSocialRows(null, SOCIAL_SHARED_READY, 'facebook');
    fakeFacebook('fb-1');

    [$back] = facebookSignIn($this);

    expect($back)->toContain('/admin/login?oauth_error=not_linked')
        ->and(SocialIdentity::query()->count())->toBe(0);
    $this->assertGuest();
    $this->get('http://a.test/admin/login?oauth_error=not_linked')->assertOk()->assertSee(__('admin.oauth_login_not_linked'));
});

it('tells a linked Facebook account that it is not a member of this website', function (): void {
    seedSocialRows(null, SOCIAL_SHARED_READY, 'facebook');
    $outsider = User::factory()->create();
    SocialIdentity::query()->create(['user_id' => $outsider->getKey(), 'provider' => 'facebook', 'sub' => 'fb-2']);
    fakeFacebook('fb-2');

    [$back] = facebookSignIn($this);

    expect($back)->toContain('/admin/login?oauth_error=not_member');
    $this->assertGuest();
});

it('sends a linked member who signs in with Facebook to the emailed code, then records how', function (): void {
    seedSocialRows(null, SOCIAL_SHARED_READY, 'facebook');
    Notification::fake();
    $member = quickLoginMember();
    SocialIdentity::query()->create(['user_id' => $member->getKey(), 'provider' => 'facebook', 'sub' => 'fb-3']);
    fakeFacebook('fb-3', null);

    [$back, $nonce] = facebookSignIn($this);
    expect($back)->toContain('/admin/oauth/finish?token=');

    // Facebook proves nothing about the mailbox: the second factor is still asked for.
    $this->withCookie('oauth_login_nonce', $nonce)->get($back)->assertRedirect(route('admin.otp.challenge'));
    $this->assertGuest();
    expect(session('2fa.user_id'))->toBe($member->getKey());

    $code = null;
    Notification::assertSentTo($member, EmailOtpNotification::class, function (EmailOtpNotification $note) use (&$code): bool {
        $code = (new ReflectionProperty($note, 'code'))->getValue($note);

        return true;
    });
    Livewire::test(EmailOtpChallenge::class)->set('code', (string) $code)->call('verify')->assertHasNoErrors();

    $this->assertAuthenticatedAs($member->fresh());
    expect(json_decode((string) socialAuditRow('auth.login.succeeded')?->meta, true))
        ->toMatchArray(['factor' => 'email_otp', 'provider' => 'facebook']);
});

it('asks for the authenticator code whenever the member turned it on, however they signed in', function (bool $sameEmail): void {
    seedSocialRows(null, SOCIAL_SHARED_READY);
    $member = quickLoginMember(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
    SocialIdentity::query()->create(['user_id' => $member->getKey(), 'provider' => 'google', 'sub' => 'g-2']);
    fakeGoogle('g-2', $sameEmail ? $member->email : 'other-'.$member->email);

    [$back, $nonce] = googleSignIn($this);
    $this->withCookie('oauth_login_nonce', $nonce)->get($back)->assertRedirect(route('admin.two-factor.challenge'));

    $this->assertGuest();
    expect(session('2fa.user_id'))->toBe($member->getKey());
})->with(['Google email is the account email' => [true], 'another Google email' => [false]]);

it('signs a member in with Google at once when Google vouches for the account email itself', function (): void {
    seedSocialRows(null, SOCIAL_SHARED_READY);
    Notification::fake();
    $member = quickLoginMember();
    fakeGoogle('g-3', strtoupper($member->email)); // email addresses compare without case

    [$back, $nonce] = googleSignIn($this);
    $this->withCookie('oauth_login_nonce', $nonce)->get($back)->assertRedirect('http://a.test/admin');

    $this->assertAuthenticatedAs($member->fresh());
    Notification::assertNothingSent();
    expect(json_decode((string) socialAuditRow('auth.login.succeeded')?->meta, true))
        ->toMatchArray(['factor' => 'social', 'provider' => 'google']);
});

it('asks for the emailed code when a linked Google account has another email than the account', function (): void {
    seedSocialRows(null, SOCIAL_SHARED_READY);
    Notification::fake();
    $member = quickLoginMember();
    SocialIdentity::query()->create(['user_id' => $member->getKey(), 'provider' => 'google', 'sub' => 'g-4']);
    fakeGoogle('g-4', 'personal-'.$member->email);

    [$back, $nonce] = googleSignIn($this);
    $this->withCookie('oauth_login_nonce', $nonce)->get($back)->assertRedirect(route('admin.otp.challenge'));

    $this->assertGuest();
    Notification::assertSentTo($member, EmailOtpNotification::class);
});

it('records which Facebook account a member linked', function (): void {
    seedSocialRows(null, SOCIAL_SHARED_READY, 'facebook');
    $member = User::factory()->create();
    fakeFacebook('fb-4', 'mine@example.com');

    $start = $this->actingAs($member)->get('http://a.test/admin/oauth/facebook/start?purpose=connect');
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
    $this->get('http://a.test/oauth/callback?code=the-code&state='.$query['state'])
        ->assertRedirectContains('/admin/account?tab=linked&oauth=connected');

    expect(SocialIdentity::query()->where('user_id', $member->getKey())->value('sub'))->toBe('fb-4');
    $row = socialAuditRow('auth.social.linked');
    expect($row?->actor_id)->toBe($member->getKey())
        ->and(json_decode((string) $row?->meta, true))->toBe(['provider' => 'facebook']);
});

it('tells a Google account whose email Google has not verified how to link it', function (): void {
    seedSocialRows(null, SOCIAL_SHARED_READY);
    $member = quickLoginMember();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']),
        'openidconnect.googleapis.com/*' => Http::response(['sub' => 'g-1', 'email' => $member->email, 'email_verified' => false]),
    ]);

    $start = $this->get('http://a.test/admin/oauth/google/start?purpose=login');
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->get('http://a.test/oauth/callback?code=the-code&state='.$query['state'])
        ->assertRedirectContains('/admin/login?oauth_error=not_linked');
    $this->assertGuest();
    expect(SocialIdentity::query()->count())->toBe(0);
});

it('words a quick sign-in and its service on the activity log', function (): void {
    $row = (new AuditLog)->forceFill(['action' => 'auth.login.succeeded', 'meta' => ['factor' => 'social', 'provider' => 'facebook']]);

    expect((new AuditEntryView([], 'Bảo Sơn'))->details($row))->toBe([
        ['label' => __('admin.audit.meta.factor'), 'value' => __('admin.audit.meta.factor_social')],
        ['label' => __('admin.audit.meta.provider'), 'value' => 'Facebook'],
    ]);
});
