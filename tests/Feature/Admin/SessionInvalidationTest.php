<?php

use App\Modules\Admin\Livewire\Settings;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
 * AuthenticateSession on the admin + platform protected groups: rotating a user's password
 * (a self-service change or an owner-driven reset) invalidates that user's OTHER sessions —
 * a stolen/opened-elsewhere cookie is kicked on its next full-page load. The session key is
 * `password_hash_<guard>` (guard = web), seeded on the first authenticated request.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('kicks a session that carries a stale password hash after the password rotates', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));
    $staleHash = $user->getAuthPassword();

    // The password is rotated elsewhere (e.g. the owner completes a reset). The already-open
    // session still holds the OLD hash → AuthenticateSession must log it out on the next request.
    $user->forceFill(['password' => Hash::make('rotated-password-123')])->save();

    $this->actingAs($user->fresh())
        ->withSession(['password_hash_web' => $staleHash])
        ->get('http://a.test/admin')
        ->assertRedirect('http://a.test/admin/login');

    $this->assertGuest();
});

it('does not kick a pre-existing session that has no stored password hash', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    // A session opened before AuthenticateSession was deployed has no password_hash_web key. It
    // must be seeded (not logged out) on the first request — so deploy forces no mass re-login.
    $this->actingAs($user->fresh())
        ->get('http://a.test/admin')
        ->assertOk();

    $this->assertAuthenticated();
});

it('re-seeds this session on a self password change so the changer is not kicked', function (): void {
    $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    Livewire::actingAs($user->fresh())
        ->test(Settings::class)
        ->set('current_password', 'old-password-123')
        ->set('password', 'brand-new-pass-456')
        ->set('password_confirmation', 'brand-new-pass-456')
        ->call('changePassword')
        ->assertHasNoErrors();

    // The current session now carries the NEW hash, so AuthenticateSession will not kick the
    // changer on their next full-page load (every other session still holds the old hash).
    expect(session('password_hash_web'))->toBe($user->fresh()->getAuthPassword());
});
