<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Velzon-parity admin topbar chrome (V-A2 branding): the shared tools partial
 * (language · fullscreen · light/dark · notifications) + the user area (avatar +
 * name + primary-role line) with an account menu wired to REAL routes only. The
 * platform console reuses the tools but carries no tenant account links.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('shows the user name, primary-role label, and a real-flag language switcher', function (): void {
    $user = User::factory()->create(['name' => 'Topbar Tester']);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $this->actingAs($user->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('Topbar Tester', false)          // user area shows the name
        ->assertSee('Editor', false)                 // Str::headline('editor') — primary-role label
        ->assertSee('langOpen', false)               // language switcher rendered
        ->assertSee('images/admin/flags/', false);   // a vendored flag image, not an emoji
});

it('wires the user menu to real routes and keeps notifications an honest placeholder', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $this->actingAs($user->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee(route('admin.settings.security', absolute: false), false) // "My Account" → own-account security (no profile route exists)
        ->assertSee(route('admin.logout', absolute: false), false)            // sign-out posts to the real logout route
        ->assertSee('notifOpen', false)                                       // notifications button present
        ->assertDontSee('topbar-badge', false)                                // NO Velzon fake unread-count badge
        ->assertSee("adminTheme.set('scheme'", false)                         // light/dark toggle shares the Customizer store
        ->assertSee('requestFullscreen', false);                              // fullscreen toggle present
});

it('renders the sidebar logo at the reference sizes (48px full, 22px collapsed mark)', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $this->actingAs($user->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('brand-logo--light h-12', false)   // full logo = 48px
        ->assertSee('brand-logo--sm h-[22px]', false); // collapsed rail mark = 22px
});

it('gives the platform console the shared tools but omits tenant account links', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $this->actingAs($operator->fresh())->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee('langOpen', false)                                            // shared topbar tools present
        ->assertSee("adminTheme.set('scheme'", false)
        ->assertSee('Super Admin', false)                                         // operator's primary-role label
        ->assertSee(route('platform.logout', absolute: false), false)            // platform sign-out
        ->assertDontSee(route('admin.settings.security', absolute: false), false); // no tenant account link on the operator plane
});

it('gives the platform console a menu button that opens the sidebar drawer on small screens', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    // Below 1024px the sidebar is a drawer: the menu button toggles it, Esc and the backdrop close
    // it, the closed drawer takes no focus and the open one keeps Tab inside it.
    $this->actingAs($operator->fresh())->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee('aria-controls="platform-sidebar"', false)
        ->assertSee('@click="open = ! open"', false)
        ->assertSee('@keydown.escape.window="close()"', false)
        ->assertSee('x-trap.noautofocus="open && ! wide"', false)
        ->assertSee('$el.inert = mobile && ! open', false);
});
