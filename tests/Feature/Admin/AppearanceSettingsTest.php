<?php

use App\Modules\Admin\Livewire\AppearanceSettings;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * Appearance settings page — the SITE-default tier of the admin theme (site_settings
 * group `admin_theme`, gated `settings.manage`). Only explicitly pinned axes persist;
 * '' means inherit, so saving it drops the key (per-field merge in the resolver).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('is denied without settings.manage (route + component boot)', function (): void {
    $tenancy = app(Tenancy::class);
    $member = User::factory()->create();

    $tenancy->enter(1, function () use ($member): void {
        grantRole($member, 'editor', ['entries.view']);

        Livewire::actingAs($member->fresh())->test(AppearanceSettings::class)->assertForbidden();
    });

    $this->actingAs($member->fresh())
        ->get('http://a.test/admin/settings/appearance')
        ->assertForbidden();
});

it('persists only the pinned axes to the admin_theme group', function (): void {
    $tenancy = app(Tenancy::class);
    $owner = User::factory()->create();

    $tenancy->enter(1, function () use ($owner): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);

        Livewire::actingAs($owner->fresh())
            ->test(AppearanceSettings::class)
            ->set('scheme', 'dark')
            ->set('position', 'scrollable')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('admin-chrome-changed');

        expect(app(SettingsRepository::class)->get('admin_theme'))
            ->toBe(['scheme' => 'dark', 'position' => 'scrollable']);
    });
});

it('rejects a tampered value and stores nothing', function (): void {
    $tenancy = app(Tenancy::class);
    $owner = User::factory()->create();

    $tenancy->enter(1, function () use ($owner): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);

        Livewire::actingAs($owner->fresh())
            ->test(AppearanceSettings::class)
            ->set('scheme', '"><script>alert(1)</script>')
            ->call('save')
            ->assertHasErrors(['scheme']);

        expect(app(SettingsRepository::class)->get('admin_theme'))->toBeNull();
    });
});

it('loads the stored pins and drops a key set back to inherit', function (): void {
    $tenancy = app(Tenancy::class);
    $owner = User::factory()->create();

    $tenancy->enter(1, function () use ($owner): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);
        app(SettingsRepository::class)->put('admin_theme', 'config', ['scheme' => 'dark', 'topbar' => 'dark']);

        Livewire::actingAs($owner->fresh())
            ->test(AppearanceSettings::class)
            ->assertSet('scheme', 'dark')
            ->assertSet('topbar', 'dark')
            ->assertSet('sidebar', '')
            ->set('scheme', '')
            ->call('save')
            ->assertHasNoErrors();

        expect(app(SettingsRepository::class)->get('admin_theme'))->toBe(['topbar' => 'dark']);
    });
});

it('appears in the settings nav only for settings.manage holders', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($owner, $member): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);
        grantRole($member, 'editor', ['entries.view']);
    });

    $this->actingAs($owner->fresh())->get('http://a.test/admin/settings/security')
        ->assertOk()
        ->assertSee(route('admin.settings.appearance'));

    $this->actingAs($member->fresh())->get('http://a.test/admin/settings/security')
        ->assertOk()
        ->assertDontSee(route('admin.settings.appearance'));
});
