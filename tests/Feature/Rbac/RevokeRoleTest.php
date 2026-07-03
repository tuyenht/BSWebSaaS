<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\RevokeRole;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * RevokeRole — the mirror of AssignRole. It routes through the same hierarchy guard, so the
 * no-escalation invariant covers revocation too: you may only revoke a role you could grant
 * (strictly below your level, within your permissions, same team). Removing it writes an audit.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Seed a tenant role (with level) inside $site's team. */
function seedSiteRole(int $site, string $name, int $level, array $permissions): Role
{
    return app(Tenancy::class)->enter($site, function () use ($name, $level, $permissions): Spatie\Permission\Contracts\Role {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $level, 'is_system' => true])->save();
        $role->syncPermissions($permissions);

        return $role;
    });
}

it('revokes a strictly-lower role and writes an audit trail', function (): void {
    $owner = User::factory()->create();
    $target = User::factory()->create();
    $editor = seedSiteRole(1, 'editor', 50, ['entries.view']);
    seedSiteRole(1, 'owner', 90, PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner, $target, $editor): void {
        $owner->assignRole('owner');
        $target->assignRole($editor);
    });

    app(Tenancy::class)->enter(1, fn () => app(RevokeRole::class)->handle($owner->fresh(), $target, $editor));

    $stillHasRole = app(Tenancy::class)->enter(1, fn () => $target->fresh()->hasRole('editor'));

    expect($stillHasRole)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'rbac.role.revoked')->exists())->toBeTrue();
});

it('refuses to revoke a role above the actor (no stripping a superior)', function (): void {
    $editor = User::factory()->create();
    $victim = User::factory()->create();
    seedSiteRole(1, 'editor', 50, ['entries.view']);
    $admin = seedSiteRole(1, 'admin', 70, PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($editor, $victim, $admin): void {
        $editor->assignRole('editor');
        $victim->assignRole($admin);
    });

    app(Tenancy::class)->enter(1, fn () => app(RevokeRole::class)->handle($editor->fresh(), $victim, $admin));
})->throws(AuthorizationException::class);
