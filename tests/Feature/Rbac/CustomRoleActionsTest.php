<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\CreateCustomRole;
use App\Modules\Rbac\Actions\DeleteCustomRole;
use App\Modules\Rbac\Actions\UpdateCustomRole;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteModule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * Custom roles — the RBAC Console's write path (tier-2 inc-4, owner's two-level model 06/07).
 * System roles stay locked; a site entitled to `rbac.custom_roles` may fork one or start blank.
 * Invariants: R1 a role never carries a permission outside the modules the SITE is entitled to ·
 * R2 nor one the EDITOR doesn't hold (what they can't hold, they can't add or strip) · R3 its level
 * sits strictly below the editor · R4 system roles are immutable, a custom role is deletable only
 * once nobody holds it · R5 implications normalized on save · R6 every change audited. The RBAC
 * tables are global (no RLS), so a role from another site must be refused before any guard runs.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Site $site becomes a `corporate` site (every module) with custom roles switched on/off. */
function consoleSite(int $site, bool $customRoles = true): void
{
    DB::connection('pgsql_admin')->table('sites')->where('id', $site)
        ->update(['site_type_id' => DB::table('site_types')->where('key', 'corporate')->value('id')]);

    if ($customRoles) {
        app(Tenancy::class)->enter($site, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.custom_roles', 'value' => true]));
    }
}

/** A role row in $site's team with an explicit level + permissions (system template or ad-hoc). */
function consoleRole(int $site, string $name, int $level, array $permissions, bool $system = true): Role
{
    return app(Tenancy::class)->enter($site, function () use ($name, $level, $permissions, $system): Role {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $level, 'is_system' => $system])->save();
        $role->syncPermissions($permissions);

        return $role;
    });
}

/** The canonical corporate template $template, materialized as a system role in $site. */
function consoleTemplateRole(int $site, string $template): Role
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$template];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];

    return consoleRole($site, $template, $tpl['level'], $perms);
}

/** A fresh user holding the canonical corporate template $template in $site. */
function consoleTemplateUser(int $site, string $template): User
{
    return consoleUserWith($site, consoleTemplateRole($site, $template));
}

function consoleUserWith(int $site, Role $role): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => $user->assignRole($role));

    return $user;
}

/** Run $fn inside $site's tenant context. */
function consoleIn(int $site, Closure $fn): mixed
{
    return app(Tenancy::class)->enter($site, $fn);
}

it('creates a blank custom role below the creator, normalized and audited', function (): void {
    consoleSite(1);
    $owner = consoleTemplateUser(1, 'owner');

    $role = consoleIn(1, fn (): Role => app(CreateCustomRole::class)->handle($owner->fresh(), '  Chỉ duyệt bài ', 40, ['entries.approve']));

    expect($role->name)->toBe('Chỉ duyệt bài')
        ->and($role->level)->toBe(40)
        ->and($role->is_system)->toBeFalse()
        ->and($role->site_id)->toBe(1)
        ->and($role->permissions->pluck('name')->all())->toEqualCanonicalizing(['entries.approve', 'entries.view']) // R5
        ->and(consoleIn(1, fn (): bool => AuditLog::query()->where('action', 'rbac.role.created')->exists()))->toBeTrue(); // R6
});

it('forks a system role, remembering where it came from', function (): void {
    consoleSite(1);
    $owner = consoleTemplateUser(1, 'owner');
    $editor = consoleTemplateRole(1, 'editor');
    $editorPerms = PermissionRegistry::tenantRoleTemplates('corporate')['editor']['permissions'];
    $withoutEditing = array_values(array_diff($editorPerms, ['entries.edit.any', 'entries.delete.any']));

    $fork = consoleIn(1, fn (): Role => app(CreateCustomRole::class)->handle($owner->fresh(), 'Người duyệt', 45, $withoutEditing, $editor));

    expect($fork->forked_from_role_id)->toBe($editor->getKey())
        ->and($fork->permissions->pluck('name')->all())->toEqualCanonicalizing($withoutEditing);
});

it('refuses to create a custom role when the site is not entitled to them', function (): void {
    consoleSite(1, customRoles: false);
    $owner = consoleTemplateUser(1, 'owner');

    consoleIn(1, fn () => app(CreateCustomRole::class)->handle($owner->fresh(), 'Nhóm A', 20, ['entries.view']));
})->throws(RoleChangeRejectedException::class, 'custom_roles_disabled');

it('refuses a permission from a module the site is not entitled to (R1)', function (): void {
    // No site_type: the site is entitled to the `pages` module only.
    consoleIn(1, function (): void {
        SiteFeature::query()->create(['feature_key' => 'rbac.custom_roles', 'value' => true]);
        SiteModule::query()->create(['module_key' => 'pages', 'enabled' => true]);
    });
    $owner = consoleTemplateUser(1, 'owner');

    // Control: a permission the `pages` module owns goes through.
    $pagesRole = consoleIn(1, fn (): Role => app(CreateCustomRole::class)->handle($owner->fresh(), 'Nhóm trang', 20, ['entries.edit.any']));
    expect($pagesRole->permissions->pluck('name')->all())->toEqualCanonicalizing(['entries.edit.any', 'entries.view']);

    // The owner HOLDS catalog.view — only the site ceiling can refuse it.
    consoleIn(1, fn () => app(CreateCustomRole::class)->handle($owner->fresh(), 'Nhóm SP', 20, ['catalog.view']));
})->throws(AuthorizationException::class, 'this site is entitled to');

it('refuses a permission the creator does not hold (R2)', function (): void {
    consoleSite(1);
    $lead = consoleUserWith(1, consoleRole(1, 'lead', 60, ['roles.manage', 'entries.view', 'entries.create']));

    consoleIn(1, fn () => app(CreateCustomRole::class)->handle($lead->fresh(), 'Nhóm B', 20, ['catalog.manage']));
})->throws(AuthorizationException::class, 'this site is entitled to');

it('refuses a level at or above the creator (R3)', function (): void {
    consoleSite(1);
    $admin = consoleTemplateUser(1, 'admin');

    consoleIn(1, fn () => app(CreateCustomRole::class)->handle($admin->fresh(), 'Nhóm C', 70, ['entries.view']));
})->throws(RoleChangeRejectedException::class, 'level_invalid');

it('refuses a reserved name, whatever its case', function (): void {
    consoleSite(1);
    $owner = consoleTemplateUser(1, 'owner');

    consoleIn(1, fn () => app(CreateCustomRole::class)->handle($owner->fresh(), 'Super_Admin', 20, ['entries.view']));
})->throws(RoleChangeRejectedException::class, 'name_reserved');

it('refuses a name already used on this site, whatever its case', function (): void {
    consoleSite(1);
    $owner = consoleTemplateUser(1, 'owner');
    consoleRole(1, 'Kế toán', 20, ['forms.view'], system: false);

    consoleIn(1, fn () => app(CreateCustomRole::class)->handle($owner->fresh(), 'kế toán', 20, ['entries.view']));
})->throws(RoleChangeRejectedException::class, 'name_taken');

it('refuses anyone without roles.manage', function (): void {
    consoleSite(1);
    $editor = consoleTemplateUser(1, 'editor');

    consoleIn(1, fn () => app(CreateCustomRole::class)->handle($editor->fresh(), 'Nhóm D', 20, ['entries.view']));
})->throws(AuthorizationException::class, 'This action is unauthorized');

it('never lets a system role be edited or deleted (R4)', function (): void {
    consoleSite(1);
    $owner = consoleTemplateUser(1, 'owner');
    $viewer = consoleTemplateRole(1, 'viewer');

    expect(fn () => consoleIn(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $viewer, 'viewer', 10, ['entries.view'])))
        ->toThrow(AuthorizationException::class, 'custom role of this site')
        ->and(fn () => consoleIn(1, fn () => app(DeleteCustomRole::class)->handle($owner->fresh(), $viewer)))
        ->toThrow(AuthorizationException::class, 'custom role of this site');
});

it('updates a custom role and audits the permission diff', function (): void {
    consoleSite(1);
    $owner = consoleTemplateUser(1, 'owner');
    $role = consoleRole(1, 'Nhóm E', 20, ['entries.view', 'media.view'], system: false);

    consoleIn(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $role, 'Nhóm E2', 25, ['entries.view', 'catalog.manage']));

    $role->refresh();
    $log = consoleIn(1, fn () => AuditLog::query()->where('action', 'rbac.role.updated')->first());

    expect($role->name)->toBe('Nhóm E2')
        ->and($role->level)->toBe(25)
        ->and($role->permissions->pluck('name')->all())->toEqualCanonicalizing(['entries.view', 'catalog.manage', 'catalog.view'])
        ->and($log?->meta['added'] ?? null)->toEqualCanonicalizing(['catalog.manage', 'catalog.view'])
        ->and($log?->meta['removed'] ?? null)->toBe(['media.view']);
});

it('keeps what the editor cannot hold — they can neither add nor strip it (R2)', function (): void {
    consoleSite(1);
    $lead = consoleUserWith(1, consoleRole(1, 'lead', 60, ['roles.manage', 'entries.view', 'entries.create']));
    $role = consoleRole(1, 'Nhóm F', 20, ['entries.view', 'media.view'], system: false);

    consoleIn(1, fn () => app(UpdateCustomRole::class)->handle($lead->fresh(), $role, 'Nhóm F', 20, ['entries.view', 'entries.create']));

    expect($role->fresh()?->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['entries.view', 'entries.create', 'media.view']);
});

it('refuses to edit a custom role at or above the editor (R3)', function (): void {
    consoleSite(1);
    $lead = consoleUserWith(1, consoleRole(1, 'lead', 60, ['roles.manage', 'entries.view']));
    $peer = consoleRole(1, 'Nhóm G', 60, ['entries.view'], system: false);

    consoleIn(1, fn () => app(UpdateCustomRole::class)->handle($lead->fresh(), $peer, 'Nhóm G', 50, ['entries.view']));
})->throws(AuthorizationException::class, 'custom role of this site');

it('refuses a role that belongs to another site', function (): void {
    consoleSite(1);
    consoleSite(2);
    $owner = consoleTemplateUser(1, 'owner');
    $foreign = consoleRole(2, 'Nhóm H', 20, ['entries.view'], system: false);

    consoleIn(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $foreign, 'Nhóm H', 20, ['entries.view']));
})->throws(AuthorizationException::class, 'custom role of this site');

it('deletes a custom role only once nobody holds it, even with the feature switched off', function (): void {
    consoleSite(1, customRoles: false);
    $owner = consoleTemplateUser(1, 'owner');
    $role = consoleRole(1, 'Nhóm I', 20, ['entries.view'], system: false);
    $member = consoleUserWith(1, $role);

    expect(fn () => consoleIn(1, fn () => app(DeleteCustomRole::class)->handle($owner->fresh(), $role)))
        ->toThrow(RoleChangeRejectedException::class, 'in_use');

    consoleIn(1, fn () => $member->removeRole($role));
    consoleIn(1, fn () => app(DeleteCustomRole::class)->handle($owner->fresh(), $role));

    expect(Role::query()->whereKey($role->getKey())->exists())->toBeFalse()
        ->and(consoleIn(1, fn (): bool => AuditLog::query()->where('action', 'rbac.role.deleted')->exists()))->toBeTrue();
});

it('never offers a lower custom role that carries a permission the granter lacks', function (): void {
    // design.md §15.3 DEFERRED (3): unrepresentable with the monotonic templates, real with custom roles.
    consoleSite(1);
    $lead = consoleUserWith(1, consoleRole(1, 'lead', 60, ['roles.manage', 'users.manage', 'entries.view']));
    consoleRole(1, 'Nhóm J', 20, ['entries.view', 'catalog.view', 'catalog.manage'], system: false);
    consoleRole(1, 'Nhóm K', 20, ['entries.view'], system: false);

    $offered = consoleIn(1, fn (): array => app(RoleHierarchyGuard::class)->assignableRoles($lead->fresh())->pluck('name')->all());

    expect($offered)->toContain('Nhóm K')->not->toContain('Nhóm J');
});
