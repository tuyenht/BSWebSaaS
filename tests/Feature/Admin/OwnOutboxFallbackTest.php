<?php

use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Support\MailHostGuard;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * A website's own outbox that stops working (its Google sign-in revoked, a key it can no longer read) must
 * not swallow the website's mail: mail leaves through the platform's shared outbox meanwhile, the
 * «Email» page says the own outbox is failing, and a test send still reports the outbox picked.
 * (A password changed at the provider is seen only when the server refuses the send; apply() can
 * catch a sign-in it cannot refresh and a stored password it can no longer read.)
 */

beforeEach(function (): void {
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    app()->instance(MailHostGuard::class, new MailHostGuard(fn (string $host): array => ['93.184.216.34']));
    Cache::forget('mail-own-outbox-failing:1');
    app(PlatformSettings::class)->put('email', 'config', [
        'method' => 'smtp_password',
        'from_address' => 'no-reply@nen-tang.test',
        'smtp' => ['host' => 'smtp.nen-tang.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'relay', 'password' => SecretCipher::encrypt('pw')],
    ]);
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    Cache::forget('mail-own-outbox-failing:1');
});

function oofGoogleMailbox(): void
{
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', [
        'provider' => 'google',
        'method' => 'oauth2',
        'from_address' => 'chu@cong-ty.test',
        'oauth' => [
            'provider' => 'google',
            'client_id' => 'cid',
            'client_secret' => SecretCipher::encrypt('csecret'),
            'refresh_token' => SecretCipher::encrypt('revoked-'.uniqid()),
            'email' => 'chu@cong-ty.test',
        ],
    ]));
}

it('sends through the shared outbox when the website\'s own sign-in was revoked', function (): void {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    oofGoogleMailbox();

    app(Tenancy::class)->enter(1, function (): void {
        $mailer = app(TenantMailer::class);

        expect($mailer->apply())->toBeTrue()
            ->and(config('mail.default'))->toBe('smtp')
            ->and(config('mail.mailers.smtp.host'))->toBe('smtp.nen-tang.test')
            ->and(config('mail.from.address'))->toBe('no-reply@nen-tang.test')
            ->and(config('mail.from.name'))->not->toBe(config('admin.brand.name'))
            ->and($mailer->ownOutboxFailing())->toBeTrue();
    });
});

it('does not fall back for a test send, so the test reports the outbox the website picked', function (): void {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    oofGoogleMailbox();

    app(Tenancy::class)->enter(1, function (): void {
        $mailer = app(TenantMailer::class);

        expect($mailer->apply(fallback: false))->toBeFalse()
            ->and(config('mail.default'))->toBe('log')
            ->and($mailer->ownOutboxFailing())->toBeTrue();
    });
});

it('clears the failing note on the own outbox\'s next good send', function (): void {
    Cache::put('mail-own-outbox-failing:1', true, 600);
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', [
        'provider' => 'other',
        'method' => 'smtp_password',
        'from_address' => 'chu@cong-ty.test',
        'smtp' => ['host' => 'smtp.cong-ty.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'chu', 'password' => SecretCipher::encrypt('pw2')],
    ]));

    app(Tenancy::class)->enter(1, function (): void {
        $mailer = app(TenantMailer::class);

        expect($mailer->apply())->toBeTrue()
            ->and(config('mail.mailers.smtp.host'))->toBe('smtp.cong-ty.test')
            ->and($mailer->ownOutboxFailing())->toBeFalse();
    });
});

it('tells the website on the «Email» page that its own outbox is failing and mail goes through the shared one', function (): void {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    oofGoogleMailbox();
    app(Tenancy::class)->enter(1, fn () => app(TenantMailer::class)->apply());

    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin->fresh())->test(EmailSettings::class)
            ->assertSee(__('admin.em_status_wait', ['name' => __('admin.em_ob_google')]))
            ->assertSee(__('admin.em_status_fallback'));
    });
});

it('tells a Google mailbox owner how to set up an app that keeps sending, and only on the Google row', function (string $provider, bool $shown): void {
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', [
        'provider' => $provider,
        'method' => 'oauth2',
        'oauth' => ['provider' => $provider],
    ]));
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    app(Tenancy::class)->enter(1, function () use ($admin, $shown): void {
        $page = Livewire::actingAs($admin->fresh())->test(EmailSettings::class);
        $shown ? $page->assertSee(__('admin.oauth_guide_google_tip')) : $page->assertDontSee(__('admin.oauth_guide_google_tip'));
    });
})->with([
    'Google' => ['google', true],
    'Microsoft' => ['microsoft', false],
]);

it('never leaves the previous website\'s mail server in place when the own outbox fails and there is no shared one', function (): void {
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.website-truoc.test']); // left by an earlier request in this worker
    oofGoogleMailbox();

    app(Tenancy::class)->enter(1, function (): void {
        expect(app(TenantMailer::class)->apply())->toBeFalse()
            ->and(config('mail.default'))->toBe('log');
    });
});

it('falls back when the website\'s own mail-server password can no longer be read', function (): void {
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'config', [
        'provider' => 'other',
        'method' => 'smtp_password',
        'from_address' => 'chu@cong-ty.test',
        'smtp' => ['host' => 'smtp.cong-ty.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'chu', 'password' => 'not-a-readable-secret'],
    ]));

    app(Tenancy::class)->enter(1, function (): void {
        $mailer = app(TenantMailer::class);

        expect($mailer->apply())->toBeTrue()
            ->and(config('mail.mailers.smtp.host'))->toBe('smtp.nen-tang.test')
            ->and($mailer->ownOutboxFailing())->toBeTrue();
    });
});

it('reports «Gửi thử» as failed for a broken own outbox instead of sending through the shared one', function (): void {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    Mail::fake();
    oofGoogleMailbox();
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin->fresh())->test(EmailSettings::class)
            ->call('sendTest')
            ->assertSet('testResult', 'error');
    });
    Mail::assertNothingOutgoing();
});

it('forgets the failing note once the website saves its outbox again', function (): void {
    Cache::put('mail-own-outbox-failing:1', true, 600);
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin->fresh())->test(EmailSettings::class)->call('save');
    });

    expect(Cache::has('mail-own-outbox-failing:1'))->toBeFalse();
});
