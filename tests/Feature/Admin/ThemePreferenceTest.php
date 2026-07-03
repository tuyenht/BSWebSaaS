<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/*
 * Per-user Customizer persistence (fetch/JSON endpoints behind the floating launcher).
 * Writes merge per field into users.preferences.theme through the allow-list — the model
 * guards `preferences` against mass assignment, so this controller is the only write path.
 * The route is mirrored on both planes (different hosts, same-origin fetch).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();

    // These cover the endpoints' auth / allow-list / merge logic, not CSRF — the routes keep
    // CSRF in prod and the launcher sends X-CSRF-TOKEN from the <meta> tag. (This stack does
    // not auto-skip CSRF for test POSTs, so disable that middleware here.)
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function prefMember(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    return $user->fresh();
}

it('pins a submitted axis and echoes the resolved attributes', function (): void {
    $user = prefMember();

    $this->actingAs($user)
        ->postJson('http://a.test/admin/theme-preference', ['scheme' => 'dark'])
        ->assertOk()
        ->assertJsonPath('attributes.data-bs-theme', 'dark');

    expect($user->fresh()->preferences['theme'])->toBe(['scheme' => 'dark']);
});

it('merges per field instead of replacing the whole override', function (): void {
    $user = prefMember();
    $user->preferences = ['theme' => ['sidebar' => 'light']];
    $user->save();

    $this->actingAs($user->fresh())
        ->postJson('http://a.test/admin/theme-preference', ['topbar' => 'dark'])
        ->assertOk();

    // jsonb normalizes key order, so compare contents, not order.
    expect($user->fresh()->preferences['theme'])
        ->toEqualCanonicalizing(['sidebar' => 'light', 'topbar' => 'dark']);
});

it('rejects values outside the allow-list with a JSON 422 and writes nothing', function (): void {
    $user = prefMember();

    $this->actingAs($user)
        ->postJson('http://a.test/admin/theme-preference', ['scheme' => 'hotdog', 'position' => 'floaty', 'sidebar_image' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['scheme', 'position', 'sidebar_image']);

    expect($user->fresh()->preferences)->toBe([]);
});

it('turns a guest away to the admin login', function (): void {
    // Exceptions render as web outside api/* (bootstrap shouldRenderJsonWhen), so an
    // unauthenticated POST redirects rather than 401s — nothing is ever written.
    $this->postJson('http://a.test/admin/theme-preference', ['scheme' => 'dark'])
        ->assertRedirect(route('admin.login'));
});

it('drops the whole override on reset so the site default shows through', function (): void {
    $user = prefMember();
    $user->preferences = ['theme' => ['scheme' => 'dark'], 'other' => 'kept'];
    $user->save();

    $this->actingAs($user->fresh())
        ->deleteJson('http://a.test/admin/theme-preference')
        ->assertOk()
        ->assertJsonPath('attributes.data-bs-theme', 'light');

    $preferences = $user->fresh()->preferences;
    expect($preferences)->not->toHaveKey('theme')
        ->and($preferences['other'])->toBe('kept'); // reset touches only the theme namespace
});

it('resets only the scheme on the platform console, keeping tenant-plane pins', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $operator = $operator->fresh();
    $operator->preferences = ['theme' => ['scheme' => 'dark', 'sidebar' => 'light']];
    $operator->save();

    $this->actingAs($operator->fresh())
        ->deleteJson("http://{$host}/theme-preference")
        ->assertOk()
        ->assertJsonPath('attributes.data-bs-theme', 'light');

    // The console launcher only shows Scheme — its reset must not silently drop the
    // operator's sidebar pin for tenant admins.
    expect($operator->fresh()->preferences['theme'])->toBe(['sidebar' => 'light']);
});

it('persists on the platform plane through the mirrored route', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $this->actingAs($operator->fresh())
        ->postJson("http://{$host}/theme-preference", ['scheme' => 'dark'])
        ->assertOk()
        ->assertJsonPath('attributes.data-bs-theme', 'dark');

    expect($operator->fresh()->preferences['theme'])->toBe(['scheme' => 'dark']);
});

it('pins new visual axes and echoes their attributes', function (): void {
    $user = prefMember();

    $this->actingAs($user)
        ->postJson('http://a.test/admin/theme-preference', ['sidebar' => 'gradient-3', 'sidebar_size' => 'sm'])
        ->assertOk()
        ->assertJsonPath('attributes.data-sidebar', 'gradient-3')
        ->assertJsonPath('attributes.data-sidebar-size', 'sm');

    expect($user->fresh()->preferences['theme'])
        ->toEqualCanonicalizing(['sidebar' => 'gradient-3', 'sidebar_size' => 'sm']);
});
