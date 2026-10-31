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
 * on read (the values render into attributes). The platform console emits only the scheme
 * and the sidebar size (its menu button's collapse): never sidebar/topbar/position variants.
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
        ->assertSee('data-layout-position="fixed"', false)
        ->assertSee('data-layout="vertical"', false)
        ->assertSee('data-layout-width="fluid"', false)
        ->assertSee('data-sidebar-size="lg"', false)
        ->assertSee('data-sidebar-image="none"', false);
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

it('emits only the scheme and the sidebar size on the platform console', function (): void {
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
        ->assertSee('data-sidebar-size="lg"', false) // the menu button's collapse, default full
        ->assertDontSee('data-sidebar=', false)
        ->assertDontSee('data-layout-position=', false)
        ->assertDontSee('data-layout=', false)
        ->assertDontSee('data-layout-width=', false)
        ->assertDontSee('data-sidebar-image=', false);
});

it('renders the platform console collapsed when the operator pinned the small sidebar', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $operator = $operator->fresh();
    $operator->preferences = ['theme' => ['sidebar_size' => 'sm', 'sidebar_image' => 'img-2']];
    $operator->save();

    $html = (string) $this->actingAs($operator->fresh())->get("http://{$host}/tenants")->assertOk()->getContent();
    $htmlTag = (string) strstr((string) strstr($html, '<html'), '>', true);

    expect($htmlTag)->toContain('data-sidebar-size="sm"')
        ->toContain('data-bs-theme="light"')
        ->not->toContain('data-sidebar-image='); // other tenant axes stay off the console
    // theme-boot re-applies an unsaved size before paint, with the same allow-list (@js escapes quotes).
    $js = fn (string $json): string => str_replace('"', '\u0022', $json);
    expect($html)->toContain($js('"sidebar_size":{"attr":"data-sidebar-size","values":["lg","sm"]}'))
        ->not->toContain($js('"sidebar_image":{'));
});

it('applies the new axes when a user pins them', function (): void {
    $user = themeMember();
    $user->preferences = ['theme' => [
        'layout_width' => 'boxed',
        'sidebar' => 'gradient-2',
        'sidebar_size' => 'sm',
        'sidebar_image' => 'img-3',
    ]];
    $user->save();

    $this->actingAs($user->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('data-layout-width="boxed"', false)
        ->assertSee('data-sidebar="gradient-2"', false)
        ->assertSee('data-sidebar-size="sm"', false)
        ->assertSee('data-sidebar-image="img-3"', false);
});

it('prints the platform brand colour as one :root block in the head, not on <html>', function (): void {
    config(['admin.brand.color' => 'green']);

    $html = $this->actingAs(themeMember())->get('http://a.test/admin/content')->assertOk()->getContent();
    $head = (string) strstr((string) $html, '</head>', true);

    expect(substr_count($head, '--color-primary:'))->toBe(1)
        ->and($head)->toContain('<style>:root{--color-primary-base:#15803d;--color-primary:#056b30;--color-primary-text:#0a7636;--color-brand-50:')
        ->and((string) $html)->not->toMatch('/<html[^>]*style=/');
});

it('prints the default brand colour for an unknown key', function (): void {
    config(['admin.brand.color' => '"><script>alert(1)</script>']);

    $this->actingAs(themeMember())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('<style>:root{--color-primary-base:#2050a0;--color-primary:#2050a0;--color-primary-text:#2050a0;', false)
        ->assertDontSee('alert(1)', false);
});

it('prints the brand colour on the platform console too', function (): void {
    config(['admin.brand.color' => 'plum']);
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $this->actingAs($operator->fresh())->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee('--color-primary:#9d174d;', false);
});
