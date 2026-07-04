<?php

use App\Modules\Admin\Livewire\LoginSecuritySettings;
use App\Modules\Admin\Support\LoginTurnstile;
use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The system-wide login-Turnstile toggle (platform console, super_admin only). Guarded in boot() AND
 * save() so the livewire/update endpoint can't be driven by a non-operator.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate platform_settings restart identity cascade');
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('lets a super admin turn the login Turnstile off and persists it system-wide', function (): void {
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));

    Livewire::actingAs($operator)->test(LoginSecuritySettings::class)
        ->assertSet('turnstileLogin', true) // default on
        ->set('turnstileLogin', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(LoginTurnstile::enabled())->toBeFalse()
        ->and(app(PlatformSettings::class)->get('security'))->toBe(['turnstile_login_enabled' => false]);
});

it('forbids a non-super-admin from the login security settings', function (): void {
    $user = User::factory()->create(); // no platform role

    Livewire::actingAs($user)->test(LoginSecuritySettings::class)->assertForbidden();
});
