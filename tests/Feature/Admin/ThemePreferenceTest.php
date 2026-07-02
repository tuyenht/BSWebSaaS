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

beforeEach(function () {
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

it('pins a submitted axis and echoes the resolved attributes', function () {
    $user = prefMember();

    $this->actingAs($user)
        ->postJson('http://a.test/admin/theme-preference', ['scheme' => 'dark'])
        ->assertOk()
        ->assertJsonPath('attributes.data-bs-theme', 'dark');

    expect($user->fresh()->preferences['theme'])->toBe(['scheme' => 'dark']);
});

it('merges per field instead of replacing the whole override', function () {
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

it('rejects values outside the allow-list with a JSON 422 and writes nothing', function () {
    $user = prefMember();

    $this->actingAs($user)
        ->postJson('http://a.test/admin/theme-preference', ['scheme' => 'hotdog', 'position' => 'floaty'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['scheme', 'position']);

    expect($user->fresh()->preferences)->toBe([]);
});

it('turns a guest away to the admin login', function () {
    // Exceptions render as web outside api/* (bootstrap shouldRenderJsonWhen), so an
    // unauthenticated POST redirects rather than 401s — nothing is ever written.
    $this->postJson('http://a.test/admin/theme-preference', ['scheme' => 'dark'])
        ->assertRedirect(route('admin.login'));
});

it('drops the whole override on reset so the site default shows through', function () {
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

it('persists on the platform plane through the mirrored route', function () {
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
