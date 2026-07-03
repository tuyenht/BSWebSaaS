<?php

use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Navigation\NavItem;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * AdminNav (design.md §15.2): the admin menu is ModuleCatalog ∩ site entitlement
 * (site_type set ∪ site_modules override) ∩ RBAC. Feature modules link to their own route
 * with an i18n label; content slots come from a provisioned content_section. Fail-closed.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         platform_settings, site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();

    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'An Vinh Owner',
        ownerPassword: 'a-strong-password', locales: ['vi'], modules: ['cms', 'media'],
    ));
});

it('returns an empty menu for a guest (fail-closed)', function (): void {
    expect((new AdminNav)->items(null))->toBe([]);
});

it('produces feature-module items that link to their own admin route for the entitled owner', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner));
    $byKey = collect($items)->keyBy('key');

    expect($byKey->has('dashboard'))->toBeTrue()
        ->and($byKey->get('dashboard')->url)->toContain('/admin')
        ->and($byKey->has('settings'))->toBeTrue()
        ->and($byKey->get('settings')->url)->toContain('/admin/settings')
        ->and($byKey->get('settings')->icon)->toBe('settings');
});

it('excludes a feature module whose nav_permission the user lacks (entitled ∩ RBAC)', function (): void {
    // A member with only media.view: media surfaces, users (needs users.manage) does not.
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($member, 'media-only', ['media.view']));

    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($member));
    $keys = array_map(fn (NavItem $i): string => $i->key, $items);

    expect($keys)->toContain('media')
        ->and($keys)->not->toContain('users');
});
