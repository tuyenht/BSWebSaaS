<?php

use App\Modules\Admin\Livewire\AdminUiSettings;
use App\Modules\Admin\Livewire\CatalogSettings;
use App\Modules\Admin\Livewire\Concerns\TracksSavedState;
use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Livewire\FloatingContactSettings;
use App\Modules\Admin\Livewire\GeneralSettings;
use App\Modules\Admin\Livewire\IntegrationsSettings;
use App\Modules\Admin\Livewire\LoginSecuritySettings;
use App\Modules\Admin\Livewire\SeoSettings;
use App\Modules\Admin\Livewire\SocialSettings;
use App\Modules\Admin\Livewire\WebsiteAppearanceSettings;
use App\Modules\Admin\Support\LoginTurnstile;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SecretCipher;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The one save bar of the settings area: every section that saves shows the same bar under it
 * ("Chưa có thay đổi" · "Bỏ thay đổi" · "Lưu"), both buttons off while nothing differs from what is
 * saved, on once something does; "Bỏ thay đổi" puts the saved values back without a reload, and the
 * save still asks for the settings permission.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->table('platform_settings')->where('group_name', 'oauth_social')->delete();   // platform_settings is never rolled back
    Livewire::flushState();   // a full-page GET leaves Livewire's asset-injection flag set for the next file's pages
});

function sbAdmin(): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

/** A website admin whose platform opened Google and Facebook, so quick sign-in's switches show. */
function sbSocialAdmin(): User
{
    config(['admin.encryption_key' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef')]);
    foreach (['google', 'facebook'] as $provider) {
        app(PlatformSettings::class)->put('oauth_social', $provider, ['client_id' => $provider.'-shared', 'client_secret' => SecretCipher::encrypt('shared-secret'), 'enabled' => true, 'status' => 'ok']);
    }

    return sbAdmin();
}

/** A platform operator (super_admin) for the system-wide login security, its switch saved on. */
function sbOperator(): User
{
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformSettings::class)->put(LoginTurnstile::GROUP, 'config', [LoginTurnstile::KEY => true]);

    return $operator->fresh();
}

/** An admin of a site with the rich header (so "Có tìm kiếm" on Giao diện website), its search saved on. */
function sbHeaderAdmin(): User
{
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => ['search' => true]]));

    return sbAdmin();
}

/** The opening tag of the bar's button marked `$mark`. */
function sbButton(string $html, string $mark): string
{
    expect(preg_match('/<button\b[^>]*\b'.$mark.'\b[^>]*>/', $html, $m))->toBe(1);

    return $m[0];
}

/** Whether the bar shows nothing to save: "Bỏ thay đổi" off, "Lưu" announced as off, the state clean. */
function sbClean(string $html): bool
{
    $discardOff = preg_match('/(?<=\s)disabled(?=[\s>=])/', sbButton($html, 'data-sb-discard')) === 1;
    $saveOff = str_contains(sbButton($html, 'data-sb-save'), ' aria-disabled="true"');
    $stateClean = preg_match('/data-sb-state[^>]*data-clean/', $html) === 1;
    expect($discardOff)->toBe($saveOff)->toBe($stateClean);

    return $stateClean;
}

it('shows exactly one bar on every settings section that saves, with the shared words', function (string $path): void {
    $html = (string) $this->actingAs(sbAdmin())->get('http://a.test'.$path)->assertOk()
        ->assertSee('role="region" aria-label="Lưu thay đổi"', false)
        ->assertSee('Chưa có thay đổi')
        ->assertSee('Bỏ thay đổi')
        ->assertDontSee('Lưu cấu hình')
        ->getContent();

    expect(substr_count($html, 'data-set-savebar'))->toBe(1)
        ->and(substr_count($html, 'data-sb-save='))->toBe(1)
        ->and(sbClean($html))->toBeTrue();
})->with([
    'general' => ['/admin/settings/general'],
    'seo' => ['/admin/settings/seo'],
    'website appearance' => ['/admin/settings/appearance'],
    'admin panel appearance' => ['/admin/settings/admin-ui'],
    'email' => ['/admin/settings/email'],
    'quick sign-in' => ['/admin/settings/social'],
    'catalog' => ['/admin/settings/catalog'],
    'floating contact' => ['/admin/settings/floating-contact'],
    'integrations' => ['/admin/settings/integrations'],
]);

it('turns the bar on after one change, and "Bỏ thay đổi" puts the saved value back', function (string $page, Closure $change, string $property, mixed $saved, ?Closure $who = null): void {
    $admin = $who instanceof Closure ? $who() : sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $page, $change, $property, $saved): void {
        $lw = Livewire::actingAs($admin)->test($page);
        expect(sbClean($lw->html()))->toBeTrue();

        $change($lw);
        expect(sbClean($lw->html()))->toBeFalse()
            ->and($lw->html())->toContain('1 thay đổi chưa lưu')->not->toContain('<span x-text="cleanText">Chưa có thay đổi</span>');

        $lw->call('resetChanges')->assertSet($property, $saved);
        expect(sbClean($lw->html()))->toBeTrue();
    });
})->with([
    'general' => [GeneralSettings::class, fn (Testable $lw) => $lw->set('form.phone', '0901 234 567'), 'form.phone', ''],
    'seo' => [SeoSettings::class, fn (Testable $lw) => $lw->set('form.template', '%tieu-de%'), 'form.template', ''],
    'admin panel appearance' => [AdminUiSettings::class, fn (Testable $lw) => $lw->set('scheme', 'dark'), 'scheme', ''],
    'email' => [EmailSettings::class, fn (Testable $lw) => $lw->set('fromName', 'Cửa hàng A'), 'fromName', ''],
    'quick sign-in' => [SocialSettings::class, fn (Testable $lw) => $lw->call('toggle', 'google')->call('confirmAsk'), 'on.google', true, fn () => sbSocialAdmin()],
    'catalog' => [CatalogSettings::class, fn (Testable $lw) => $lw->set('mode', 'commerce'), 'mode', 'brochure'],
    'floating contact' => [FloatingContactSettings::class, fn (Testable $lw) => $lw->call('toggleEnabled'), 'enabled', false],
    'integrations' => [IntegrationsSettings::class, fn (Testable $lw) => $lw->set('tokens.google', 'abcdefghijklmnopqrstuvwxyz'), 'tokens.google', ''],
    'login security' => [LoginSecuritySettings::class, fn (Testable $lw) => $lw->set('turnstileLogin', false), 'turnstileLogin', true, fn () => sbOperator()],
    'website appearance: "Có tìm kiếm"' => [WebsiteAppearanceSettings::class, fn (Testable $lw) => $lw->call('toggleSearch'), 'search', true, fn () => sbHeaderAdmin()],
]);

it('goes back to nothing to save once the change is saved', function (string $page, Closure $change, ?Closure $who = null): void {
    $admin = $who instanceof Closure ? $who() : sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $page, $change): void {
        $lw = Livewire::actingAs($admin)->test($page);
        $change($lw);
        expect(sbClean($lw->html()))->toBeFalse();

        $lw->call('save')->assertHasNoErrors();
        expect(sbClean($lw->html()))->toBeTrue();
    });
})->with([
    'admin panel appearance' => [AdminUiSettings::class, fn (Testable $lw) => $lw->set('scheme', 'dark')],
    'email' => [EmailSettings::class, fn (Testable $lw) => $lw->set('fromName', 'Cửa hàng A')],
    'quick sign-in' => [SocialSettings::class, fn (Testable $lw) => $lw->call('toggle', 'google')->call('confirmAsk'), fn () => sbSocialAdmin()],
    'catalog' => [CatalogSettings::class, fn (Testable $lw) => $lw->set('mode', 'commerce')],
    'login security' => [LoginSecuritySettings::class, fn (Testable $lw) => $lw->set('turnstileLogin', false), fn () => sbOperator()],
    'website appearance: "Có tìm kiếm"' => [WebsiteAppearanceSettings::class, fn (Testable $lw) => $lw->call('toggleSearch'), fn () => sbHeaderAdmin()],
]);

it('saves every quick sign-in switch that changed with the one "Lưu"', function (): void {
    $admin = sbSocialAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SocialSettings::class)
            ->call('toggle', 'google')->call('confirmAsk')
            ->call('toggle', 'facebook')->call('confirmAsk')
            ->call('save')->assertHasNoErrors();

        Livewire::actingAs($admin)->test(SocialSettings::class)
            ->assertSet('on.google', false)
            ->assertSet('on.facebook', false);
        expect(app(SettingsRepository::class)->get('oauth_social', 'google'))->toBe(['on' => false, 'own' => false]);
    });
});

it('answers "Lưu" with nothing to save on quick sign-in without an error', function (): void {
    $admin = sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(SocialSettings::class)->call('save')->assertOk()->assertHasNoErrors();
        expect(sbClean($lw->html()))->toBeTrue();
    });
});

it('keeps the section\'s message above the bar, never over "Lưu"', function (): void {
    $this->actingAs(sbAdmin())->get('http://a.test/admin/settings/general')->assertOk()
        ->assertSee('bottom-[max(1rem,calc(var(--sb-h,0px)+0.5rem),env(safe-area-inset-bottom),var(--toast-over,0px))]', false);
});

it('still refuses the save and the discard once the settings permission is gone', function (string $page, string $action, bool $operator = false): void {
    $admin = $operator ? sbOperator() : sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $page, $action, $operator): void {
        $lw = Livewire::actingAs($admin)->test($page);
        if ($operator) {
            app(PlatformAccess::class)->onPlatform(fn () => $admin->removeRole('super_admin'));
            app(PlatformAccess::class)->forgetMemo();   // a new request asks again
        } else {
            $admin->roles()->firstOrFail()->revokePermissionTo('settings.manage');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        auth()->setUser($admin->fresh());
        $lw->call($action)->assertForbidden();
    });
})->with([
    'general save' => [GeneralSettings::class, 'save'],
    'admin panel appearance save' => [AdminUiSettings::class, 'save'],
    'admin panel appearance discard' => [AdminUiSettings::class, 'resetChanges'],
    'email save' => [EmailSettings::class, 'save'],
    'email discard' => [EmailSettings::class, 'resetChanges'],
    'quick sign-in save' => [SocialSettings::class, 'save'],
    'quick sign-in discard' => [SocialSettings::class, 'resetChanges'],
    'catalog save' => [CatalogSettings::class, 'save'],
    'catalog discard' => [CatalogSettings::class, 'resetChanges'],
    'floating contact save' => [FloatingContactSettings::class, 'save'],
    'floating contact discard' => [FloatingContactSettings::class, 'resetChanges'],
    'login security save (no longer super admin)' => [LoginSecuritySettings::class, 'save', true],
    'login security discard (no longer super admin)' => [LoginSecuritySettings::class, 'resetChanges', true],
]);

it('never sends a typed secret back to the browser in the saved state', function (): void {
    $admin = sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(EmailSettings::class)->set('smtpPassword', 'mat-khau-bi-mat');
        $state = json_encode($lw->get('savedState'));
        expect($state)->not->toContain('mat-khau-bi-mat')
            ->and($lw->html())->toContain('1 thay đổi chưa lưu');
    });
});

it('takes the saved switch again on "Bỏ thay đổi", so a change made elsewhere meanwhile is not counted', function (): void {
    $operator = sbOperator();
    $lw = Livewire::actingAs($operator)->test(LoginSecuritySettings::class)->assertSet('turnstileLogin', true);

    // another operator turns it off while this screen is open
    app(PlatformSettings::class)->put(LoginTurnstile::GROUP, 'config', [LoginTurnstile::KEY => false]);

    $lw->call('resetChanges')->assertSet('turnstileLogin', false);
    expect(sbClean($lw->html()))->toBeTrue();
});

it('writes nothing, and no audit entry, when login security is saved with nothing changed', function (): void {
    $operator = sbOperator();
    $before = AuditLog::query()->where('action', 'platform.security.turnstile_login')->count();

    $lw = Livewire::actingAs($operator)->test(LoginSecuritySettings::class)->call('save')->assertOk()->assertHasNoErrors();

    expect(AuditLog::query()->where('action', 'platform.security.turnstile_login')->count())->toBe($before)
        ->and($lw->effects['returns'] ?? null)->toBe([false])
        ->and(LoginTurnstile::enabled())->toBeTrue();
});

it('never stores a login security switch that is not on or off', function (): void {
    $operator = sbOperator();
    $before = AuditLog::query()->where('action', 'platform.security.turnstile_login')->count();
    $lw = Livewire::actingAs($operator)->test(LoginSecuritySettings::class);

    try {
        $lw->set('turnstileLogin', ['off'])->call('save');
    } catch (Throwable) {
        // refused before save (a value of the wrong type never reaches the switch)
    }

    expect(LoginTurnstile::enabled())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'platform.security.turnstile_login')->count())->toBe($before);
});

it('answers the bar with true only when a section really saved', function (string $page, Closure $change, ?Closure $who = null): void {
    $admin = $who instanceof Closure ? $who() : sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $page, $change): void {
        $lw = Livewire::actingAs($admin)->test($page);
        $change($lw);
        $lw->call('save')->assertHasNoErrors();
        expect($lw->effects['returns'] ?? null)->toBe([true]);
    });
})->with([
    'general' => [GeneralSettings::class, fn (Testable $lw) => $lw->set('i18n.vi.name', 'Cơ khí An Vinh')->set('form.legalName', 'CÔNG TY TNHH CƠ KHÍ AN VINH')],
    'admin panel appearance' => [AdminUiSettings::class, fn (Testable $lw) => $lw->set('scheme', 'dark')],
    'email' => [EmailSettings::class, fn (Testable $lw) => $lw->set('fromName', 'Cửa hàng A')],
    'quick sign-in' => [SocialSettings::class, fn (Testable $lw) => $lw->call('toggle', 'google')->call('confirmAsk'), fn () => sbSocialAdmin()],
    'catalog' => [CatalogSettings::class, fn (Testable $lw) => $lw->set('mode', 'commerce')],
    'floating contact' => [FloatingContactSettings::class, fn (Testable $lw) => $lw->call('toggleEnabled')],
    'integrations' => [IntegrationsSettings::class, fn (Testable $lw) => $lw->set('tokens.google', 'abcdefghijklmnopqrstuvwxyz')],
    'login security' => [LoginSecuritySettings::class, fn (Testable $lw) => $lw->set('turnstileLogin', false), fn () => sbOperator()],
    'website appearance: "Có tìm kiếm"' => [WebsiteAppearanceSettings::class, fn (Testable $lw) => $lw->call('toggleSearch'), fn () => sbHeaderAdmin()],
]);

it('answers false when quick sign-in has nothing to save', function (): void {
    $admin = sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(SocialSettings::class)->call('save');
        expect($lw->effects['returns'] ?? null)->toBe([false]);
    });
});

it('keeps the quick sign-in providers out of the browser\'s reach, so a tampered request writes no other key', function (): void {
    $admin = sbSocialAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(SocialSettings::class);
        expect(fn () => $lw->set('on', ['google' => true, 'evil' => true]))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $lw->set('apps', []))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $lw->set('scope', 'platform'))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $lw->set('askProvider', 'evil'))->toThrow(CannotUpdateLockedPropertyException::class);

        // a provider the screen does not offer: neither the switch nor "Lưu" nor «Dùng khóa riêng» stores it
        $lw->call('toggle', 'evil')->call('save')->assertOk();
        $lw->call('useOwn', 'evil', 'x', 'y')->assertOk();

        expect(app(SettingsRepository::class)->get('oauth_social', 'evil'))->toBeNull()
            ->and(array_keys($lw->get('on')))->toBe(['google', 'facebook']);
    });
});

it('keeps the email screen\'s scope out of the browser\'s reach', function (): void {
    $admin = sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(EmailSettings::class);
        expect(fn () => $lw->set('scope', 'platform'))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $lw->set('isSuperAdmin', true))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('counts an emptied list as its removed rows, not one more', function (): void {
    $section = new class
    {
        use TracksSavedState;

        /** @var array<string, mixed> */
        public array $state = [];

        protected function trackedState(): array
        {
            return $this->state;
        }

        public function remember(): void
        {
            $this->rememberSaved();
        }

        public function count(): int
        {
            return $this->unsavedCount();
        }

        /** @return list<string> */
        public function fields(): array
        {
            return $this->unsavedFields();
        }
    };

    $section->state = ['rows' => ['a', 'b', 'c'], 'empty' => [], 'name' => 'x'];
    $section->remember();
    expect($section->count())->toBe(0);

    $section->state['rows'] = [];
    expect($section->count())->toBe(3)->and($section->fields())->toBe(['rows']);

    $section->state['rows'] = ['a'];
    expect($section->count())->toBe(2);

    $section->state = ['rows' => ['a', 'b', 'c'], 'empty' => ['new'], 'name' => 'x'];
    expect($section->count())->toBe(1)->and($section->fields())->toBe(['empty']);
});

it('says plainly when the bar could not load, with a "Lưu" that works without it and a "Thử lại" that keeps the page', function (): void {
    $html = (string) $this->actingAs(sbAdmin())->get('http://a.test/admin/settings/general')->assertOk()->getContent();

    expect($html)->toContain('data-bs-lazy="bsSaveBar" data-bs-lazy-keep')
        ->toContain('Không tải được thanh lưu. Những gì đã nhập vẫn còn: bấm «Lưu» để lưu ngay, hoặc «Thử lại».')
        ->toMatch('/<div[^>]*data-bs-lazy-error[^>]*>/')
        ->toMatch('/<button[^>]*data-bs-lazy-retry[^>]*>/')
        ->toMatch('/<div wire:ignore data-bs-lazy-wait id="sb-st-/')
        ->toMatch('/<div wire:ignore data-bs-lazy-wait class="ms-auto/');
    expect(preg_match('/<button type="submit"[^>]*form="(sb-st-[0-9a-f]{6}-f)"[^>]*data-sb-fallback/', $html, $m))->toBe(1)
        ->and($html)->toContain('<form id="'.$m[1].'" wire:submit="save" hidden></form>');
});

it('sends Enter in a settings form through the bar, never straight to the save', function (string $page, ?Closure $who = null): void {
    $admin = $who instanceof Closure ? $who() : sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $page): void {
        $html = Livewire::actingAs($admin)->test($page)->html();

        expect($html)->not->toMatch('/<form(?![^>]*\\bhidden\\b)[^>]*wire:submit/')
            ->toMatch('/<form x-data x-on:submit.prevent[\s>]/')
            ->toContain('x-on:keydown.enter.document="if (! $event.isComposing')
            ->toContain('{ $event.preventDefault(); go() }"');
    });
})->with([
    'admin panel appearance' => [AdminUiSettings::class],
    'email' => [EmailSettings::class],
    'login security' => [LoginSecuritySettings::class, fn () => sbOperator()],
]);

it('draws "Bỏ thay đổi" as the quiet neutral button of design 01, not the blue outline', function (): void {
    $html = (string) $this->actingAs(sbAdmin())->get('http://a.test/admin/settings/general')->assertOk()->getContent();
    $discard = sbButton($html, 'data-sb-discard');

    expect($discard)->toContain('border border-border-input bg-surface text-text hover:bg-surface-hover')
        ->not->toContain('border-admin-secondary-text');
});
