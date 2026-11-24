<?php

use App\Modules\Admin\Livewire\SocialSettings;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SocialAuth;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * The «Đăng nhập nhanh» screen's two faces. The platform's («Đăng nhập nhanh hệ thống», super admin
 * only): each provider's shared app is connected with «Kiểm tra & lưu», which asks the provider
 * about the keys before saving them, checked again with «Kiểm tra lại», disconnected after a
 * question, and switched on or off for every website with the save bar. A website's: its switch
 * shows only where an app can serve, saved with the bar, and its own app is chosen or dropped at
 * once. Secrets never reach the page.
 */

const SS_GOOGLE_ID = '812734-abcxyz.apps.googleusercontent.com';
const SS_GOOGLE_SECRET = 'GOCSPX-the-shared-secret';

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate platform_settings, social_identities restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    app()->setLocale('vi');
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
});

// platform_settings is never rolled back: the shared apps written here must not reach the next file.
afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
});

function ssOperator(string $role = 'super_admin'): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

function ssSiteAdmin(): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

/** @param  array<string, mixed>  $extra */
function ssSharedApp(string $provider = 'google', array $extra = []): void
{
    app(PlatformSettings::class)->put('oauth_social', $provider, array_merge([
        'client_id' => SS_GOOGLE_ID,
        'client_secret' => SecretCipher::encrypt(SS_GOOGLE_SECRET),
        'enabled' => true,
        'status' => 'ok',
    ], $extra));
}

function ssGoogleAnswers(string $error, int $status = 400): void
{
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => $error], $status)]);
}

function ssAudits(string $action): int
{
    return AuditLog::query()->where('action', $action)->count();
}

// ---- the platform face ------------------------------------------------------------------------

it('opens the platform face for a super admin only', function (): void {
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(ssOperator())->get("http://{$host}/settings/social")->assertOk()
        ->assertSee('Kết nối Google &amp; Facebook', false)
        ->assertSee('/oauth/callback');

    $this->actingAs(ssOperator('support'))->get("http://{$host}/settings/social")->assertForbidden();
});

it('draws one trail on the platform\'s settings pages, the settings frame\'s own', function (string $path): void {
    $host = (string) config('tenancy.platform_host');
    $html = (string) $this->actingAs(ssOperator())->get("http://{$host}{$path}")->assertOk()->getContent();

    expect(substr_count($html, 'aria-label="'.__('admin.breadcrumb').'"'))->toBe(1);
})->with(['quick sign-in' => '/settings/social', 'email' => '/settings/email']);

it('connects an app the provider accepts: saved, switched on, marked as checked, audited', function (): void {
    ssGoogleAnswers('invalid_grant');
    $before = ssAudits('platform.oauth_social.keys');

    Livewire::actingAs(ssOperator())->test(SocialSettings::class, ['scope' => 'platform'])
        ->assertSet('on.google', true)
        ->call('check', 'google', SS_GOOGLE_ID, SS_GOOGLE_SECRET)
        ->assertReturned(['ok' => true])
        ->assertSet('toast', 'Đã kết nối Google — nút đăng nhập đã hiện cho khách')
        ->assertSet('apps.google.status', 'ok')
        ->assertSet('apps.google.connected', true);

    $row = app(PlatformSettings::class)->get('oauth_social', 'google');
    expect($row['client_id'])->toBe(SS_GOOGLE_ID)
        ->and(SecretCipher::decrypt($row['client_secret']))->toBe(SS_GOOGLE_SECRET)
        ->and($row['enabled'])->toBeTrue()
        ->and($row['status'])->toBe('ok')
        ->and($row['checked_at'])->not->toBeNull()
        ->and(ssAudits('platform.oauth_social.keys'))->toBe($before + 1);
});

it('saves nothing when the provider refuses the keys, and says so under the secret', function (): void {
    ssGoogleAnswers('invalid_client', 401);

    $answer = Livewire::actingAs(ssOperator())->test(SocialSettings::class, ['scope' => 'platform'])
        ->call('check', 'google', SS_GOOGLE_ID, SS_GOOGLE_SECRET)->effects['returns'][0];

    expect($answer['ok'])->toBeFalse()
        ->and($answer['secret'])->toContain('invalid_client')
        ->and(app(PlatformSettings::class)->get('oauth_social', 'google'))->toBeNull();
});

it('saves the keys unchecked when the provider cannot answer, never as passed', function (): void {
    Http::fake(['oauth2.googleapis.com/*' => Http::response('', 503)]);
    ssSharedApp('google', ['status' => 'error', 'checked_at' => now()->subDay()->toIso8601String(), 'fails' => 2]);

    Livewire::actingAs(ssOperator())->test(SocialSettings::class, ['scope' => 'platform'])
        ->call('check', 'google', '999-new.apps.googleusercontent.com', 'GOCSPX-a-new-one')
        ->assertReturned(['ok' => true])
        ->assertSet('toastTone', 'info')
        ->assertSet('apps.google.status', 'unchecked');

    $row = app(PlatformSettings::class)->get('oauth_social', 'google');
    expect($row['client_id'])->toBe('999-new.apps.googleusercontent.com')
        ->and($row['status'])->toBeNull()
        ->and($row['checked_at'])->toBeNull()
        ->and($row['fails'])->toBe(0);
});

it('answers keys in the wrong shape or missing without asking the provider', function (string $id, string $secret, array $fields): void {
    Http::fake();

    $answer = Livewire::actingAs(ssOperator())->test(SocialSettings::class, ['scope' => 'platform'])
        ->call('check', 'google', $id, $secret)->effects['returns'][0];

    expect($answer['ok'])->toBeFalse()
        ->and(array_keys(array_diff_key($answer, ['ok' => 0])))->toBe($fields);
    Http::assertNothingSent();
})->with([
    'an id that is not Google\'s' => ['12345', SS_GOOGLE_SECRET, ['id']],
    'a secret that is not Google\'s' => [SS_GOOGLE_ID, 'not-a-secret', ['secret']],
    'nothing typed and nothing saved' => ['', '', ['id', 'secret']],
]);

it('checks with the saved secret when the secret field is left blank', function (): void {
    ssSharedApp('google', ['status' => 'error']);
    ssGoogleAnswers('invalid_grant');

    Livewire::actingAs(ssOperator())->test(SocialSettings::class, ['scope' => 'platform'])
        ->call('check', 'google', SS_GOOGLE_ID, '')
        ->assertReturned(['ok' => true]);

    Http::assertSent(fn ($request): bool => $request['client_secret'] === SS_GOOGLE_SECRET);
    expect(app(PlatformSettings::class)->get('oauth_social', 'google')['status'])->toBe('ok');
});

it('clears a broken mark with «Kiểm tra lại» once the provider accepts the saved keys', function (): void {
    ssSharedApp('google', ['status' => 'error', 'fails' => 3]);
    ssGoogleAnswers('invalid_grant');
    $before = ssAudits('platform.oauth_social.status');

    Livewire::actingAs(ssOperator())->test(SocialSettings::class, ['scope' => 'platform'])
        ->assertSet('apps.google.status', 'error')
        ->call('recheck', 'google')->assertReturned('ok')
        ->assertSet('apps.google.status', 'ok');

    expect(app(PlatformSettings::class)->get('oauth_social', 'google')['status'])->toBe('ok')
        ->and(ssAudits('platform.oauth_social.status'))->toBe($before + 1);
});

it('keeps the broken mark when «Kiểm tra lại» is still refused', function (): void {
    ssSharedApp('google', ['status' => 'error']);
    ssGoogleAnswers('invalid_client', 401);

    Livewire::actingAs(ssOperator())->test(SocialSettings::class, ['scope' => 'platform'])
        ->call('recheck', 'google')->assertReturned('refused')
        ->assertSet('toastTone', 'error');

    expect(app(PlatformSettings::class)->get('oauth_social', 'google')['status'])->toBe('error');
});

it('disconnects the shared app only after its question', function (): void {
    ssSharedApp();
    $before = ssAudits('platform.oauth_social.disconnected');

    $lw = Livewire::actingAs(ssOperator())->test(SocialSettings::class, ['scope' => 'platform'])
        ->call('askDisconnect', 'google')
        ->assertSet('askOpen', true)->assertSet('askKind', 'disconnect');
    expect(app(PlatformSettings::class)->get('oauth_social', 'google')['client_id'])->toBe(SS_GOOGLE_ID);

    $lw->call('confirmAsk')->assertSet('askOpen', false)->assertSet('apps.google.connected', false);
    expect(app(PlatformSettings::class)->get('oauth_social', 'google'))->toBe([])
        ->and(ssAudits('platform.oauth_social.disconnected'))->toBe($before + 1);
});

it('switches a provider off for every website with the save bar, after its question', function (): void {
    ssSharedApp();
    $before = ssAudits('platform.oauth_social.switch');

    Livewire::actingAs(ssOperator())->test(SocialSettings::class, ['scope' => 'platform'])
        ->call('toggle', 'google')->assertSet('askKind', 'off')->assertSet('on.google', true)
        ->call('confirmAsk')->assertSet('on.google', false)
        ->call('save')->assertReturned(true);

    expect(app(PlatformSettings::class)->get('oauth_social', 'google')['enabled'])->toBeFalse()
        ->and(ssAudits('platform.oauth_social.switch'))->toBe($before + 1);
    app(Tenancy::class)->enter(1, fn () => expect(app(SocialAuth::class)->enabled('google'))->toBeFalse());
});

it('shows the policy addresses to copy only once they are configured', function (): void {
    $operator = ssOperator();
    $html = Livewire::actingAs($operator)->test(SocialSettings::class, ['scope' => 'platform'])->html();
    expect($html)->not->toContain('https://example.test/privacy');

    config(['oauth.legal.privacy_url' => 'https://example.test/privacy', 'oauth.legal.terms_url' => 'https://example.test/terms']);
    $html = Livewire::actingAs($operator)->test(SocialSettings::class, ['scope' => 'platform'])->html();
    expect($html)->toContain('https://example.test/privacy')->toContain('https://example.test/terms');
});

it('never puts a saved secret on the page, plain or encrypted', function (): void {
    ssSharedApp();
    $cipher = app(PlatformSettings::class)->get('oauth_social', 'google')['client_secret'];

    $html = Livewire::actingAs(ssOperator())->test(SocialSettings::class, ['scope' => 'platform'])->html();

    expect($html)->toContain(SS_GOOGLE_ID)->not->toContain(SS_GOOGLE_SECRET)->not->toContain($cipher);
});

it('refuses the platform face to a website admin, in each of its actions', function (string $action, array $args): void {
    $admin = ssSiteAdmin();
    ssSharedApp();

    app(Tenancy::class)->enter(1, function () use ($admin, $action, $args): void {
        Livewire::actingAs($admin)->test(SocialSettings::class, ['scope' => 'platform'])->assertForbidden();
        Livewire::actingAs($admin)->test(SocialSettings::class)->call($action, ...$args)->assertForbidden();
    });

    expect(app(PlatformSettings::class)->get('oauth_social', 'google')['client_id'])->toBe(SS_GOOGLE_ID);
})->with([
    '«Kiểm tra & lưu»' => ['check', ['google', SS_GOOGLE_ID, SS_GOOGLE_SECRET]],
    '«Kiểm tra lại»' => ['recheck', ['google']],
    '«Ngắt kết nối»' => ['askDisconnect', ['google']],
]);

// ---- a website's face -------------------------------------------------------------------------

it('locks a website\'s switch while the platform has not opened the provider', function (): void {
    $admin = ssSiteAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(SocialSettings::class)
            ->assertSee('Chưa mở')
            ->call('toggle', 'google')->assertSet('askOpen', false)
            ->call('save')->assertReturned(false);

        expect(app(SettingsRepository::class)->get('oauth_social', 'google'))->toBeNull()
            ->and($lw->html())->not->toContain("toggle('google')");
    });
});

it('chooses the website\'s own app at once and goes back to the shared one keeping its keys', function (): void {
    ssSharedApp();
    $admin = ssSiteAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(SocialSettings::class)
            ->call('useOwn', 'google', 'own-1.apps.googleusercontent.com', 'GOCSPX-own-secret')->assertReturned(true)
            ->assertSet('apps.google.own_ready', true);
        expect(app(SocialAuth::class)->usesOwn('google'))->toBeTrue()
            ->and($lw->html())->not->toContain('GOCSPX-own-secret');

        $lw->call('useShared', 'google')->assertSet('apps.google.own', false);
        $row = app(SettingsRepository::class)->get('oauth_social', 'google');
        expect(app(SocialAuth::class)->usesOwn('google'))->toBeFalse()
            ->and(app(SocialAuth::class)->config('google')['client_id'] ?? null)->toBe(SS_GOOGLE_ID)
            ->and($row['client_id'])->toBe('own-1.apps.googleusercontent.com')
            ->and(SecretCipher::decrypt($row['client_secret']))->toBe('GOCSPX-own-secret');
    });
});

it('refuses own keys that are missing or not in the provider\'s shape, saving nothing', function (string $provider, string $id, string $secret, string $message): void {
    ssSharedApp($provider);
    $admin = ssSiteAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $provider, $id, $secret, $message): void {
        Livewire::actingAs($admin)->test(SocialSettings::class)
            ->call('useOwn', $provider, $id, $secret)->assertReturned(false)
            ->assertSet('toastTone', 'error')
            ->assertSet('toast', __($message, ['name' => ucfirst($provider)]));

        expect(app(SettingsRepository::class)->get('oauth_social', $provider))->toBeNull();
    });
})->with([
    'no secret' => ['google', SS_GOOGLE_ID, '', 'admin.sl.need_both'],
    'a Google id without its ending' => ['google', 'abc', 'GOCSPX-x', 'admin.sl.google.id_invalid'],
    'a Facebook id with letters' => ['facebook', '12ab', '0123456789abcdef0123456789abcdef', 'admin.sl.facebook.id_invalid'],
    'a Facebook secret of the wrong length' => ['facebook', '1234567890123456', 'short', 'admin.sl.facebook.secret_invalid'],
]);

it('offers a website\'s own-app form even where the platform has not opened the provider, once it uses its own keys', function (): void {
    $admin = ssSiteAdmin();
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('oauth_social', 'google', [
        'on' => true, 'own' => true, 'client_id' => 'own-1.apps.googleusercontent.com', 'client_secret' => SecretCipher::encrypt('GOCSPX-own'),
    ]));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $html = Livewire::actingAs($admin)->test(SocialSettings::class)
            ->assertSet('apps.google.own_ready', true)
            ->assertSee('Đang dùng ứng dụng riêng')
            ->html();

        expect($html)->toContain("toggle('google')")->not->toContain('GOCSPX-own');
    });
});
