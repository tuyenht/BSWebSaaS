<?php

use App\Modules\Admin\Livewire\CatalogSettings;
use App\Modules\Catalog\Support\CatalogMode;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Livewire\Livewire;

/*
 * Slice-3 — the per-site catalog_mode flag (site_settings: catalog/mode). One switch, one
 * reader (CatalogMode): brochure hides price & ordering on the storefront (P2 consumes it),
 * commerce is the phase-2 state. Fail-closed: missing/malformed settings read as brochure,
 * so a broken row can only ever HIDE commercial surfaces. The settings tab is
 * settings.manage-gated like every site-wide tab (boot() re-checks on the livewire path).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('defaults to brochure and fails closed on malformed values', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $mode = app(CatalogMode::class);

        expect($mode->current())->toBe('brochure')       // no row at all
            ->and($mode->isBrochure())->toBeTrue();

        SiteSetting::query()->create(['group_name' => 'catalog', 'key' => 'mode', 'value' => ['value' => 'garbage']]);
        expect($mode->current())->toBe('brochure');      // unknown value → brochure, never commerce
    });
});

it('saves the mode from the settings tab and CatalogMode reads it back', function (): void {
    $admin = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        grantRole($admin, 'site-admin', ['settings.manage']);

        Livewire::actingAs($admin->fresh())
            ->test(CatalogSettings::class)
            ->assertSet('mode', 'brochure')
            ->set('mode', 'commerce')
            ->call('save')
            ->assertHasNoErrors();

        expect(app(CatalogMode::class)->current())->toBe('commerce')
            ->and(SiteSetting::query()->where('group_name', 'catalog')->where('key', 'mode')->first()?->site_id)->toBe(1);
    });
});

it('rejects a tampered mode value', function (): void {
    $admin = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        grantRole($admin, 'site-admin', ['settings.manage']);

        Livewire::actingAs($admin->fresh())
            ->test(CatalogSettings::class)
            ->set('mode', 'free-everything')
            ->call('save')
            ->assertHasErrors('mode');

        expect(app(CatalogMode::class)->current())->toBe('brochure'); // nothing persisted
    });
});

it('gates the tab — 200 with settings.manage, 403 for a plain member (route + boot)', function (): void {
    $admin = User::factory()->create();
    $member = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($admin, $member): void {
        grantRole($admin, 'site-admin', ['settings.manage']);
        grantRole($member, 'plain-member', ['entries.view']);
    });

    $this->actingAs($admin->fresh())->get('http://a.test/admin/settings/catalog')->assertOk();
    $this->actingAs($member->fresh())->get('http://a.test/admin/settings/catalog')->assertForbidden();

    // The livewire path (bypasses route middleware) is also closed by boot().
    app(Tenancy::class)->enter(1, function () use ($member): void {
        Livewire::actingAs($member->fresh())->test(CatalogSettings::class)->assertForbidden();
    });
});
