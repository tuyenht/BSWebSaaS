<?php

use App\Modules\Admin\Livewire\Settings;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
 * The user's own account page (Hồ sơ & bảo mật): display-name edit and password change.
 * Both scope strictly to auth()->user(); the password change is gated by the CURRENT
 * password (never by role), and the topbar renders the shortened display name.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('updates the own display name and the topbar shows its short form', function (): void {
    $user = User::factory()->create(['name' => 'Cũ Lắm Rồi Đây']);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    Livewire::actingAs($user->fresh())
        ->test(Settings::class)
        ->assertSet('profileName', 'Cũ Lắm Rồi Đây')
        ->set('profileName', 'Trần Huy Tuyền')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('Trần Huy Tuyền');

    // Topbar shows the ≤2-word short form, not the full name.
    $this->actingAs($user->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('Trần Huy', false);
});

it('changes the own password only with the correct current password', function (): void {
    $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    // Wrong current password → friendly error, nothing changes.
    Livewire::actingAs($user->fresh())
        ->test(Settings::class)
        ->set('current_password', 'not-the-password')
        ->set('password', 'brand-new-pass-456')
        ->set('password_confirmation', 'brand-new-pass-456')
        ->call('changePassword')
        ->assertHasErrors('current_password');

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();

    // Correct current password → the new one is stored (hashed), inputs reset.
    Livewire::actingAs($user->fresh())
        ->test(Settings::class)
        ->set('current_password', 'old-password-123')
        ->set('password', 'brand-new-pass-456')
        ->set('password_confirmation', 'brand-new-pass-456')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertSet('password', '');

    expect(Hash::check('brand-new-pass-456', $user->fresh()->password))->toBeTrue();
});

it('rejects a mismatched confirmation', function (): void {
    $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    Livewire::actingAs($user->fresh())
        ->test(Settings::class)
        ->set('current_password', 'old-password-123')
        ->set('password', 'brand-new-pass-456')
        ->set('password_confirmation', 'khác-hẳn')
        ->call('changePassword')
        ->assertHasErrors('password');

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});
