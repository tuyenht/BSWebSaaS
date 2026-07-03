<?php

use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * SSR of the resolved admin-chrome theme onto <html data-*> (P5 Customizer). The chain is
 * app default → site default (site_settings group `admin_theme`) → per-user override
 * (users.preferences.theme), merged per field; anything outside the allow-list is ignored
 * on read (the values render into attributes). The platform console is a scheme-only
 * plane: it must never emit sidebar/topbar/position attributes.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function themeMember(int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'editor', ['entries.view']));

    return $user->fresh();
}

it('SSRs the app defaults when no tier pins anything', function (): void {
    $this->actingAs(themeMember())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('data-bs-theme="light"', false)
        ->assertSee('data-sidebar="dark"', false)
        ->assertSee('data-topbar="light"', false)
        ->assertSee('data-layout-position="fixed"', false);
});

it('applies the site default from the admin_theme settings group', function (): void {
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)
        ->put('admin_theme', 'config', ['scheme' => 'dark', 'sidebar' => 'light']));

    $this->actingAs(themeMember())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('data-bs-theme="dark"', false)
        ->assertSee('data-sidebar="light"', false)
        ->assertSee('data-topbar="light"', false); // unpinned axis stays on the app default
});

it('lets a per-user pin win over the site default, per field', function (): void {
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)
        ->put('admin_theme', 'config', ['scheme' => 'dark', 'topbar' => 'dark']));

    $user = themeMember();
    $user->preferences = ['theme' => ['scheme' => 'light']];
    $user->save();

    $this->actingAs($user->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('data-bs-theme="light"', false) // user pin wins
        ->assertSee('data-topbar="dark"', false);   // unpinned field: site default shows through
});

it('ignores stored values outside the allow-list (fail-safe read)', function (): void {
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)
        ->put('admin_theme', 'config', ['scheme' => 'hotdog']));

    $user = themeMember();
    $user->preferences = ['theme' => ['sidebar' => '"><script>', 'topbar' => 'dark']];
    $user->save();

    $this->actingAs($user->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('data-bs-theme="light"', false) // bad site value → default
        ->assertSee('data-sidebar="dark"', false)   // bad user value → default
        ->assertSee('data-topbar="dark"', false)    // valid user value still applies
        ->assertDontSee('hotdog');
});

it('keeps site defaults isolated per site', function (): void {
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)
        ->put('admin_theme', 'config', ['sidebar' => 'light']));

    $this->actingAs(themeMember(2))->get('http://b.test/admin/content')
        ->assertOk()
        ->assertSee('data-sidebar="dark"', false); // site 1's pin never bleeds into site 2
});

it('emits only the scheme on the platform console (scheme-only plane)', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $operator = $operator->fresh();
    $operator->preferences = ['theme' => ['scheme' => 'dark', 'sidebar' => 'light']];
    $operator->save();

    $this->actingAs($operator->fresh())->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee('data-bs-theme="dark"', false)
        ->assertDontSee('data-sidebar=', false)
        ->assertDontSee('data-layout-position=', false);
});
