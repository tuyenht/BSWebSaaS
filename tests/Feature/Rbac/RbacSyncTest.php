<?php

use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use Spatie\Permission\PermissionRegistrar;

/*
 * P0.3 — rbac:sync materializes the permission catalog + platform roles from the
 * registry, idempotently (safe on every deploy).
 */

beforeEach(fn () => truncateRbac());

it('syncs the full permission catalog and platform roles, idempotently', function () {
    $this->artisan('rbac:sync')->assertSuccessful();
    $this->artisan('rbac:sync')->assertSuccessful(); // second run must not duplicate

    expect(Permission::count())->toBe(count(PermissionRegistry::permissions()));

    app(PermissionRegistrar::class)->setPermissionsTeamId(PermissionRegistry::PLATFORM_TEAM_ID);

    $super = Role::query()
        ->where('name', 'super_admin')
        ->where('site_id', PermissionRegistry::PLATFORM_TEAM_ID)
        ->first();

    expect($super)->not->toBeNull()
        ->and($super->is_system)->toBeTrue()
        ->and($super->level)->toBe(100)
        ->and($super->permissions()->count())->toBe(count(PermissionRegistry::permissions()))
        ->and(Role::query()->where('name', 'support')->where('site_id', 0)->exists())->toBeTrue();
});
