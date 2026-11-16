<?php

use App\Modules\Admin\Livewire\AdminUiSettings;
use App\Modules\Admin\Livewire\CatalogSettings;
use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Livewire\FloatingContactSettings;
use App\Modules\Admin\Livewire\GeneralSettings;
use App\Modules\Admin\Livewire\IntegrationsSettings;
use App\Modules\Admin\Livewire\SeoSettings;
use App\Modules\Admin\Livewire\SocialSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
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
    Livewire::flushState();   // a full-page GET leaves Livewire's asset-injection flag set for the next file's pages
});

function sbAdmin(): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
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

it('turns the bar on after one change, and "Bỏ thay đổi" puts the saved value back', function (string $page, Closure $change, string $property, mixed $saved): void {
    $admin = sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $page, $change, $property, $saved): void {
        $lw = Livewire::actingAs($admin)->test($page);
        expect(sbClean($lw->html()))->toBeTrue();

        $change($lw);
        expect(sbClean($lw->html()))->toBeFalse()
            ->and($lw->html())->toContain('1 thay đổi chưa lưu')->not->toContain('Chưa có thay đổi');

        $lw->call('resetChanges')->assertSet($property, $saved);
        expect(sbClean($lw->html()))->toBeTrue();
    });
})->with([
    'general' => [GeneralSettings::class, fn (Testable $lw) => $lw->set('form.phone', '0901 234 567'), 'form.phone', ''],
    'seo' => [SeoSettings::class, fn (Testable $lw) => $lw->set('form.template', '%tieu-de%'), 'form.template', ''],
    'admin panel appearance' => [AdminUiSettings::class, fn (Testable $lw) => $lw->set('scheme', 'dark'), 'scheme', ''],
    'email' => [EmailSettings::class, fn (Testable $lw) => $lw->set('fromName', 'Cửa hàng A'), 'fromName', ''],
    'quick sign-in' => [SocialSettings::class, fn (Testable $lw) => $lw->set('forms.google.client_id', 'abc.apps'), 'forms.google.client_id', ''],
    'catalog' => [CatalogSettings::class, fn (Testable $lw) => $lw->set('mode', 'commerce'), 'mode', 'brochure'],
    'floating contact' => [FloatingContactSettings::class, fn (Testable $lw) => $lw->call('toggleEnabled'), 'enabled', false],
    'integrations' => [IntegrationsSettings::class, fn (Testable $lw) => $lw->set('tokens.google', 'abcdefghijklmnopqrstuvwxyz'), 'tokens.google', ''],
]);

it('goes back to nothing to save once the change is saved', function (string $page, Closure $change): void {
    $admin = sbAdmin();

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
    'quick sign-in' => [SocialSettings::class, fn (Testable $lw) => $lw->set('forms.google.client_id', 'abc.apps')],
    'catalog' => [CatalogSettings::class, fn (Testable $lw) => $lw->set('mode', 'commerce')],
]);

it('saves every quick sign-in app that changed with the one "Lưu", and leaves the others alone', function (): void {
    $admin = sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(SocialSettings::class)
            ->set('forms.google.client_id', 'g.apps')
            ->set('forms.facebook.client_id', 'fb-123')
            ->call('save')->assertHasNoErrors();

        Livewire::actingAs($admin)->test(SocialSettings::class)
            ->assertSet('forms.google.client_id', 'g.apps')
            ->assertSet('forms.facebook.client_id', 'fb-123');
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
        ->assertSee('bottom-[max(1rem,calc(var(--sb-h,0px)+0.5rem),env(safe-area-inset-bottom))]', false);
});

it('still refuses the save and the discard once the settings permission is gone', function (string $page, string $action): void {
    $admin = sbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $page, $action): void {
        $lw = Livewire::actingAs($admin)->test($page);
        $admin->roles()->firstOrFail()->revokePermissionTo('settings.manage');
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
