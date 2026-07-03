<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * P0.3 — defense layer #2 (RBAC). A tenant grant is scoped to its site (team =
 * site_id): the same user authorized on site A is NOT authorized on site B. A
 * platform super_admin overrides every gate, in any tenant context.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('scopes tenant permissions by site (team = site_id)', function (): void {
    $tenancy = app(Tenancy::class);
    $user = User::factory()->create();

    // Grant an editor role carrying entries.publish on site 1 only.
    $tenancy->enter(1, function () use ($user): void {
        $role = Role::findOrCreate('editor', PermissionRegistry::GUARD);
        $role->givePermissionTo('entries.publish');
        $user->assignRole($role);
    });

    expect($tenancy->enter(1, fn () => $user->fresh()->can('entries.publish')))->toBeTrue()
        ->and($tenancy->enter(2, fn () => $user->fresh()->can('entries.publish')))->toBeFalse();
});

it('lets a platform super_admin pass every gate in any tenant context', function (): void {
    $tenancy = app(Tenancy::class);
    $user = User::factory()->create();

    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));

    expect($tenancy->enter(1, fn () => $user->fresh()->can('entries.publish')))->toBeTrue()
        ->and($tenancy->enter(2, fn () => $user->fresh()->can('settings.manage')))->toBeTrue();
});
