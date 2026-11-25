<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * The console's settings pages share their Livewire component with a website's (one form, two
 * scopes): each must still be drawn in its own frame. A class-level layout attribute is applied after
 * render() and silently put the console's «Email hệ thống» and «Đăng nhập nhanh» in the website frame
 * (a menu of «Tổng quan» only, the website search box).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

it('draws every console settings page in the console frame', function (string $path): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $this->actingAs($operator->fresh())->get("http://{$host}{$path}")
        ->assertOk()
        ->assertSee('id="platform-sidebar"', false)
        ->assertDontSee('id="admin-sidebar"', false);
})->with(['/settings/email', '/settings/social', '/settings/security', '/sites']);

it('keeps a website settings page in the website frame', function (string $path): void {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['settings.manage', 'entries.view']));

    $this->actingAs($admin)->get("http://a.test{$path}")
        ->assertOk()
        ->assertSee('id="admin-sidebar"', false)
        ->assertDontSee('id="platform-sidebar"', false);
})->with(['/admin/settings/email', '/admin/settings/social']);

it('speaks of the whole platform on the console email page, not of «this website»', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $this->actingAs($operator->fresh())->get("http://{$host}/settings/email")
        ->assertOk()
        ->assertSee(__('admin.system_default_warning'))
        ->assertDontSee(__('admin.email_settings_desc'));
});
