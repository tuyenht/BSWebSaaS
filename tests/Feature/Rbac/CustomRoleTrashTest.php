<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\AssignRole;
use App\Modules\Rbac\Actions\CreateCustomRole;
use App\Modules\Rbac\Actions\DeleteCustomRole;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Actions\RestoreCustomRole;
use App\Modules\Rbac\Actions\UpdateCustomRole;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\CustomRoleRules;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Rbac\Support\VersionedPermissionRegistrar;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
 * The trash for custom roles. Deleting a custom role that nobody holds and no pending invitation
 * grants moves it to the trash: it keeps its row (id, permissions, the role it was copied from) but
 * grants nothing, is offered to nobody, and leaves every worker's permission catalog at its next
 * request. Restoring brings the same row back after the same checks as a new role (roles.manage,
 * this site, below the actor, a free name, room under the cap) and only while the site's plan has
 * custom roles. After 30 days the daily purge deletes it for good. `roles` is platform-wide (no
 * RLS), so the actions' own site checks are the isolation: another site's trash is never reached.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate invitations, invitation_roles, audit_logs, users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    trashSite(1);
    trashSite(2);
});

/** $site becomes a corporate site (every module); custom roles on or off. */
function trashSite(int $site, bool $customRoles = true): void
{
    DB::connection('pgsql_admin')->table('sites')->where('id', $site)
        ->update(['site_type_id' => DB::table('site_types')->where('key', 'corporate')->value('id')]);
    DB::connection('pgsql_admin')->table('site_features')->where('site_id', $site)->where('feature_key', 'rbac.custom_roles')->delete();

    if ($customRoles) {
        trashIn($site, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.custom_roles', 'value' => true]));
    }
}

function trashIn(int $site, Closure $fn): mixed
{
    return app(Tenancy::class)->enter($site, $fn);
}

/** A role row of $site (a system template or a custom role), written directly. */
function trashRole(int $site, string $name, int $level, array $permissions, bool $system = false, ?Role $fork = null): Role
{
    return trashIn($site, function () use ($name, $level, $permissions, $system, $fork): Role {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $level, 'is_system' => $system, 'forked_from_role_id' => $fork?->getKey()])->save();
        $role->syncPermissions($permissions);

        return $role;
    });
}

/** A fresh user holding the corporate template $template on $site. */
function trashMember(int $site, string $template): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$template];
    $role = trashRole($site, $template, $tpl['level'], $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'], system: true);
    $user = User::factory()->create();
    trashIn($site, fn () => $user->assignRole($role));

    return $user;
}

/** The role row as stored, trash included (read past the model's scope). */
function trashRow(Role $role): ?Role
{
    return Role::withTrashed()->find($role->getKey());
}

function trashAudit(int $site, string $action): ?AuditLog
{
    return trashIn($site, fn (): ?AuditLog => AuditLog::query()->where('action', $action)->latest('id')->first());
}

/** The shared token naming the current permission catalog. */
function trashCatalogVersion(): mixed
{
    return cache()->get(config('permission.cache.key').'.version');
}

/** @return list<int> the ids of every role in $registrar's permission catalog */
function trashCatalogRoleIds(PermissionRegistrar $registrar): array
{
    return $registrar->getPermissions()->flatMap(fn ($permission) => $permission->roles->pluck('id'))->unique()->values()->all();
}

function trashDelete(User $actor, Role $role, int $site = 1): void
{
    trashIn($site, fn () => app(DeleteCustomRole::class)->handle($actor->fresh(), $role));
}

function trashRestore(User $actor, Role $role, int $site = 1): Role
{
    return trashIn($site, fn (): Role => app(RestoreCustomRole::class)->handle($actor->fresh(), $role));
}

it('moves an unused custom role to the trash, where it grants nothing and is offered to nobody', function (): void {
    $owner = trashMember(1, 'owner');
    $role = trashRole(1, 'Kế toán', 20, ['entries.view', 'media.view']);

    trashDelete($owner, $role);

    $row = trashRow($role);
    expect($row?->trashed())->toBeTrue()
        ->and($row?->permissions()->pluck('name')->all())->toEqualCanonicalizing(['entries.view', 'media.view'])  // kept for a restore
        ->and(Role::query()->whereKey($role->getKey())->exists())->toBeFalse()
        ->and(trashIn(1, fn (): array => app(RoleHierarchyGuard::class)->assignableRoles($owner->fresh())->pluck('name')->all()))->not->toContain('Kế toán')
        ->and(trashCatalogRoleIds(app(PermissionRegistrar::class)))->not->toContain($role->getKey())
        ->and(trashAudit(1, 'rbac.role.deleted')?->meta['name'] ?? null)->toBe('Kế toán');

    // Even a holder written behind the actions' back gets nothing from it.
    $member = User::factory()->create();
    DB::connection('pgsql_admin')->table('model_has_roles')->insert(['role_id' => $role->getKey(), 'model_type' => User::class, 'model_id' => $member->id, 'site_id' => 1]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(trashIn(1, fn (): bool => User::query()->findOrFail($member->id)->can('media.view')))->toBeFalse()
        ->and(trashIn(1, fn (): array => User::query()->findOrFail($member->id)->roles()->pluck('name')->all()))->toBe([]);
});

it('tells every other worker at its next request, on the way into the trash and out of it', function (): void {
    $owner = trashMember(1, 'owner');
    $role = trashRole(1, 'Kế toán', 20, ['entries.view']);
    $class = app(PermissionRegistrar::class)::class;
    /** @var VersionedPermissionRegistrar $worker */
    $worker = new $class(app('cache'));
    $worker->syncWithSharedCache();
    expect(trashCatalogRoleIds($worker))->toContain($role->getKey());

    $before = trashCatalogVersion();
    trashDelete($owner, $role);
    $worker->syncWithSharedCache();

    expect(trashCatalogVersion())->not->toBe($before)
        ->and(trashCatalogRoleIds($worker))->not->toContain($role->getKey());

    $before = trashCatalogVersion();
    trashRestore($owner, $role);
    $worker->syncWithSharedCache();

    expect(trashCatalogVersion())->not->toBe($before)
        ->and(trashCatalogRoleIds($worker))->toContain($role->getKey());
});

it('leaves a role already in the trash as it is on a second delete', function (): void {
    $owner = trashMember(1, 'owner');
    $role = trashRole(1, 'Kế toán', 20, ['entries.view']);
    trashDelete($owner, $role);
    $deletedAt = trashRow($role)?->deleted_at?->toIso8601String();

    trashDelete($owner, $role);

    expect(trashRow($role)?->deleted_at?->toIso8601String())->toBe($deletedAt)
        ->and(trashIn(1, fn (): int => AuditLog::query()->where('action', 'rbac.role.deleted')->count()))->toBe(1);
});

it('refuses to give a role that went to the trash after it was read', function (string $path): void {
    $owner = trashMember(1, 'owner');
    $member = trashMember(1, 'viewer');
    $stale = trashRole(1, 'Kế toán', 20, ['entries.view']);
    $stale->load('permissions');
    trashDelete($owner, $stale);

    expect(fn () => trashIn(1, fn () => match ($path) {
        'assign' => app(AssignRole::class)->handle($owner->fresh(), $member->fresh(), $stale),
        'invite' => app(InviteMember::class)->handle($owner->fresh(), 'moi@example.test', [$stale]),
    }))->toThrow(AuthorizationException::class, 'deleted meanwhile');

    expect(DB::table('model_has_roles')->where('role_id', $stale->getKey())->exists())->toBeFalse()
        ->and(DB::connection('pgsql_admin')->table('invitation_roles')->where('role_id', $stale->getKey())->exists())->toBeFalse();
})->with(['assign', 'invite']);

it('lets a new role take the name of one in the trash, and restores the old one only once the name is free', function (): void {
    $owner = trashMember(1, 'owner');
    $old = trashRole(1, 'Kế toán', 20, ['entries.view']);
    trashDelete($owner, $old);

    $new = trashIn(1, fn (): Role => app(CreateCustomRole::class)->handle($owner->fresh(), 'kế toán', 20, ['entries.view']));

    expect(fn () => trashRestore($owner, $old))->toThrow(RoleChangeRejectedException::class, 'restore_name_taken')
        ->and(trashRow($old)?->trashed())->toBeTrue();

    trashIn(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $new, 'Kế toán mới', 20, ['entries.view']));
    trashRestore($owner, $old);

    expect(trashRow($old)?->trashed())->toBeFalse();
});

it('restores a role exactly as it was: the same row, its permissions and the role it was copied from', function (): void {
    $owner = trashMember(1, 'owner');
    $editor = trashRole(1, 'editor', 50, ['entries.view', 'entries.create'], system: true);
    $role = trashRole(1, 'Người duyệt', 45, ['entries.view', 'entries.approve'], fork: $editor);
    trashDelete($owner, $role);

    $back = trashRestore($owner, $role);

    expect($back->getKey())->toBe($role->getKey())
        ->and($back->trashed())->toBeFalse()
        ->and($back->level)->toBe(45)
        ->and($back->forked_from_role_id)->toBe($editor->getKey())
        ->and($back->permissions()->pluck('name')->all())->toEqualCanonicalizing(['entries.view', 'entries.approve'])
        ->and(trashIn(1, fn (): array => app(RoleHierarchyGuard::class)->assignableRoles($owner->fresh())->pluck('name')->all()))->toContain('Người duyệt')
        ->and(trashAudit(1, 'rbac.role.restored')?->meta['name'] ?? null)->toBe('Người duyệt');

    // a second click finds it live and changes nothing
    expect(trashRestore($owner, $role)->getKey())->toBe($role->getKey())
        ->and(trashIn(1, fn (): int => AuditLog::query()->where('action', 'rbac.role.restored')->count()))->toBe(1);
});

it('refuses a restore the same way it refuses a change', function (string $case): void {
    $role = trashRole(1, 'Kế toán', 60, ['entries.view']);
    trashDelete(trashMember(1, 'owner'), $role);

    $actor = match ($case) {
        'at the actor\'s level' => (function (): User {
            $lead = User::factory()->create();
            $leadRole = trashRole(1, 'Trưởng nhóm', 60, ['roles.manage', 'entries.view']);
            trashIn(1, fn () => $lead->assignRole($leadRole));

            return $lead;
        })(),
        'without roles.manage' => trashMember(1, 'editor'),
        'platform staff without a role on the site' => (function (): User {
            $staff = User::factory()->create();
            app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('super_admin'));
            app(PlatformAccess::class)->forgetMemo();

            return $staff;
        })(),
    };

    expect(fn () => trashRestore($actor, $role))->toThrow(AuthorizationException::class);
    expect(trashRow($role)?->trashed())->toBeTrue();
})->with(['at the actor\'s level', 'without roles.manage', 'platform staff without a role on the site']);

it('never restores a system role', function (): void {
    $owner = trashMember(1, 'owner');
    $viewer = trashRole(1, 'viewer', 10, ['entries.view'], system: true);

    trashRestore($owner, $viewer);
})->throws(AuthorizationException::class, 'custom role of this site');

it('lets a site whose plan dropped custom roles delete one, but not bring it back', function (): void {
    $owner = trashMember(1, 'owner');
    $role = trashRole(1, 'Kế toán', 20, ['entries.view']);
    trashSite(1, customRoles: false);

    expect(trashIn(1, fn (): bool => app(RestoreCustomRole::class)->availableOnThisSite()))->toBeFalse();

    trashDelete($owner, $role);

    expect(trashRow($role)?->trashed())->toBeTrue()
        ->and(fn () => trashRestore($owner, $role))->toThrow(RoleChangeRejectedException::class, 'custom_roles_disabled')
        ->and(trashRow($role)?->trashed())->toBeTrue();

    // switched on again within the 30 days, the role can come back
    trashSite(1);
    expect(trashIn(1, fn (): bool => app(RestoreCustomRole::class)->availableOnThisSite()))->toBeTrue()
        ->and(trashRestore($owner, $role)->trashed())->toBeFalse();
});

it('keeps each site\'s trash to itself', function (): void {
    $ownerA = trashMember(1, 'owner');
    $ownerB = trashMember(2, 'owner');
    $roleB = trashRole(2, 'Kế toán', 20, ['entries.view']);
    trashDelete($ownerB, $roleB, site: 2);

    // from site 1, and from site 2 by someone who is no member there
    expect(fn () => trashRestore($ownerA, $roleB, site: 1))->toThrow(AuthorizationException::class)
        ->and(fn () => trashRestore($ownerA, $roleB, site: 2))->toThrow(AuthorizationException::class)
        ->and(fn () => trashDelete($ownerA, trashRole(2, 'Kho', 20, ['entries.view']), site: 1))->toThrow(AuthorizationException::class)
        ->and(trashRow($roleB)?->trashed())->toBeTrue()
        ->and(trashIn(1, fn (): array => Role::onlyTrashed()->where('site_id', 1)->pluck('id')->all()))->toBe([]);

    // `roles` is platform-wide (no RLS, no BelongsToSite): the actions' site checks are the isolation
    $rls = DB::connection('pgsql_admin')->selectOne("select relrowsecurity from pg_class where relname = 'roles' and relkind = 'r'");
    expect((bool) $rls->relrowsecurity)->toBeFalse()
        ->and(class_uses_recursive(Role::class))->not->toContain(BelongsToSite::class);
});

it('refuses to save over a change another manager made since the editor opened the role', function (): void {
    $owner = trashMember(1, 'owner');
    $role = trashRole(1, 'Kế toán', 20, ['entries.view']);
    $opened = CustomRoleRules::fingerprint($role->fresh());

    trashIn(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $role->fresh(), 'Kế toán', 20, ['entries.view', 'media.view'], $opened));
    $now = CustomRoleRules::fingerprint($role->fresh());

    expect($now)->not->toBe($opened)
        ->and(fn () => trashIn(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $role->fresh(), 'Kế toán 2', 20, ['entries.view'], $opened)))
        ->toThrow(RoleChangeRejectedException::class, 'changed_elsewhere')
        ->and($role->fresh()?->getAttribute('name'))->toBe('Kế toán');

    trashIn(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $role->fresh(), 'Kế toán 2', 20, ['entries.view'], $now));
    expect($role->fresh()?->getAttribute('name'))->toBe('Kế toán 2');
});

it('refuses to edit a role moved to the trash since it was read', function (): void {
    $owner = trashMember(1, 'owner');
    $stale = trashRole(1, 'Kế toán', 20, ['entries.view']);
    trashDelete($owner, $stale);

    trashIn(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $stale, 'Kế toán 2', 20, ['entries.view']));
})->throws(RoleChangeRejectedException::class, 'gone');

it('refuses the name or the shown label of every system and platform role, in every language and any case', function (): void {
    $owner = trashMember(1, 'owner');
    $tried = [];
    $accepted = [];

    foreach (glob(lang_path('*.json')) ?: [] as $file) {
        $strings = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        foreach (PermissionRegistry::reservedRoleNames() as $technical) {
            foreach ([$technical, $strings['rbac.role.'.$technical] ?? $technical] as $name) {
                foreach ([$name, mb_strtoupper($name), Normalizer::normalize($name, Normalizer::FORM_D)] as $variant) {
                    $tried[] = $variant;
                    try {
                        trashIn(1, fn () => app(CreateCustomRole::class)->handle($owner->fresh(), '  '.$variant.' ', 20, ['entries.view']));
                        $accepted[] = $variant;
                    } catch (RoleChangeRejectedException $e) {
                        if ($e->reason !== 'name_reserved') {
                            $accepted[] = $variant.' ('.$e->reason.')';
                        }
                    }
                }
            }
        }
    }

    expect($tried)->toContain('Quản trị', 'QUẢN TRỊ', 'Administrator')
        ->and($accepted)->toBe([]);
});

it('treats a name typed with decomposed accents as the same name', function (): void {
    $owner = trashMember(1, 'owner');
    trashRole(1, 'Kế toán', 20, ['entries.view']);

    trashIn(1, fn () => app(CreateCustomRole::class)->handle($owner->fresh(), Normalizer::normalize('Kế toán', Normalizer::FORM_D), 20, ['entries.view']));
})->throws(RoleChangeRejectedException::class, 'name_taken');

it('keeps at most 50 live custom roles per website, the trash not counted', function (): void {
    $owner = trashMember(1, 'owner');
    $rows = [];
    for ($i = 1; $i <= CustomRoleRules::MAX_CUSTOM_ROLES; $i++) {
        $rows[] = ['site_id' => 1, 'name' => "Nhóm {$i}", 'guard_name' => PermissionRegistry::GUARD, 'level' => 10, 'is_system' => false, 'created_at' => now(), 'updated_at' => now()];
    }
    DB::connection('pgsql_admin')->table('roles')->insert($rows);
    trashRole(2, 'Nhóm khác', 10, ['entries.view']);   // another site's roles do not count

    $create = fn (string $name): Role => trashIn(1, fn (): Role => app(CreateCustomRole::class)->handle($owner->fresh(), $name, 20, ['entries.view']));
    expect(fn () => $create('Nhóm 51'))->toThrow(RoleChangeRejectedException::class, 'too_many');

    $first = trashIn(1, fn (): Role => Role::query()->where('site_id', 1)->where('name', 'Nhóm 1')->firstOrFail());
    trashDelete($owner, $first);
    $create('Nhóm 51');

    // full again: the role in the trash cannot come back until one goes
    expect(fn () => trashRestore($owner, $first))->toThrow(RoleChangeRejectedException::class, 'too_many')
        ->and(trashIn(1, fn (): int => Role::query()->where('site_id', 1)->where('is_system', false)->count()))->toBe(CustomRoleRules::MAX_CUSTOM_ROLES);
});

it('has a plain sentence for every refusal in every admin language', function (): void {
    $missing = [];
    foreach (glob(lang_path('*.json')) ?: [] as $file) {
        $strings = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        foreach (RoleChangeRejectedException::CUSTOM_ROLE_REASONS as $reason) {
            $text = $strings['rbac.role_change.'.$reason] ?? '';
            if (! is_string($text) || trim($text) === '' || ($reason === 'too_many' && ! str_contains($text, ':max'))) {
                $missing[] = basename($file).': '.$reason;
            }
        }
        foreach (['admin.audit.act.rbac.role.restored', 'admin.audit.act.rbac.role.purged', 'admin.members.inv_role_trashed'] as $key) {
            if (trim((string) ($strings[$key] ?? '')) === '') {
                $missing[] = basename($file).': '.$key;
            }
        }
    }

    expect(count(glob(lang_path('*.json')) ?: []))->toBeGreaterThanOrEqual(4)
        ->and($missing)->toBe([]);
});

it('deletes for good only the roles past 30 days in the trash, with an audit row each', function (): void {
    $owner = trashMember(1, 'owner');
    $ownerB = trashMember(2, 'owner');
    $old = trashRole(1, 'Cũ', 20, ['entries.view']);
    $recent = trashRole(1, 'Mới xóa', 20, ['entries.view']);
    $live = trashRole(1, 'Đang dùng', 20, ['entries.view']);
    $child = trashRole(1, 'Bản sao', 15, ['entries.view'], fork: $old);
    $otherSite = trashRole(2, 'Cũ', 20, ['entries.view']);
    // an expired invitation still names the old role
    $issued = trashIn(1, fn () => app(InviteMember::class)->handle($owner->fresh(), 'het-han@example.test', [$old]));
    DB::connection('pgsql_admin')->table('invitations')->where('id', $issued->invitation->getKey())->update(['expires_at' => now()->subDay()]);

    trashDelete($owner, $old);
    trashDelete($owner, $recent);
    trashDelete($ownerB, $otherSite, site: 2);
    DB::connection('pgsql_admin')->table('roles')->whereIn('id', [$old->getKey(), $otherSite->getKey()])->update(['deleted_at' => now()->subDays(Role::TRASH_DAYS + 1)]);
    DB::connection('pgsql_admin')->table('roles')->where('id', $recent->getKey())->update(['deleted_at' => now()->subDays(Role::TRASH_DAYS - 1)]);
    $before = trashCatalogVersion();

    $this->artisan('roles:purge-trash')->expectsOutputToContain('Purged 2 role(s)')->assertSuccessful();

    expect(trashRow($old))->toBeNull()
        ->and(trashRow($otherSite))->toBeNull()
        ->and(trashRow($recent)?->trashed())->toBeTrue()
        ->and(trashRow($live)?->trashed())->toBeFalse()
        ->and(trashRow($child)?->forked_from_role_id)->toBeNull()
        ->and(DB::connection('pgsql_admin')->table('invitation_roles')->where('role_id', $old->getKey())->exists())->toBeFalse()
        ->and(DB::connection('pgsql_admin')->table('role_has_permissions')->where('role_id', $old->getKey())->exists())->toBeFalse()
        ->and(trashAudit(1, 'rbac.role.purged')?->meta['name'] ?? null)->toBe('Cũ')
        ->and(trashAudit(1, 'rbac.role.purged')?->actor_label ?? null)->toBe('system')
        ->and(trashAudit(2, 'rbac.role.purged')?->auditable_id ?? null)->toBe($otherSite->getKey())
        ->and(trashCatalogVersion())->not->toBe($before);

    // --days=0 empties the trash now
    $this->artisan('roles:purge-trash', ['--days' => 0])->assertSuccessful();
    expect(trashRow($recent))->toBeNull();
});

it('purges the trash daily without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'roles:purge-trash'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('keeps the trash and the live-name unique on the roles table', function (): void {
    $indexes = collect(DB::connection('pgsql_admin')->select("select indexname, indexdef from pg_indexes where tablename = 'roles'"))
        ->mapWithKeys(fn ($row): array => [$row->indexname => $row->indexdef]);
    $constraints = collect(DB::connection('pgsql_admin')->select("select conname from pg_constraint where conrelid = 'roles'::regclass"))->pluck('conname');

    expect($indexes['roles_site_id_name_guard_name_live_unique'] ?? '')->toContain('UNIQUE INDEX')->toContain('(site_id, name, guard_name)')->toContain('WHERE (deleted_at IS NULL)')
        ->and($indexes['roles_site_id_deleted_at_index'] ?? '')->toContain('(site_id, deleted_at)')->toContain('WHERE (deleted_at IS NOT NULL)')
        ->and($constraints)->not->toContain('roles_site_id_name_guard_name_unique');
});

it('refuses to bring back a role holding a permission the actor may not give', function (): void {
    $role = trashRole(1, 'Kho ảnh', 20, ['media.view', 'media.upload']);
    trashDelete(trashMember(1, 'owner'), $role);
    $lead = User::factory()->create();
    $leadRole = trashRole(1, 'Trưởng nhóm', 60, ['roles.manage', 'media.view']);
    trashIn(1, fn () => $lead->assignRole($leadRole));

    expect(fn () => trashRestore($lead, $role))->toThrow(AuthorizationException::class, 'hold yourself')
        ->and(trashRow($role)?->trashed())->toBeTrue();
});

it('keeps letting an old role named like a system label be edited under its name', function (): void {
    $owner = trashMember(1, 'owner');
    $label = (string) json_decode((string) file_get_contents(lang_path('vi.json')), true, flags: JSON_THROW_ON_ERROR)['rbac.role.editor'];
    $old = trashRole(1, $label, 20, ['entries.view']);   // made before labels were reserved

    trashIn(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $old->fresh(), mb_strtoupper($label), 25, ['entries.view']));

    expect($old->fresh()?->level)->toBe(25)
        ->and(fn () => trashIn(1, fn () => app(CreateCustomRole::class)->handle($owner->fresh(), $label, 20, ['entries.view'])))
        ->toThrow(RoleChangeRejectedException::class, 'name_reserved');
});

it('refuses a purge window that is not a whole number of days', function (string $days): void {
    $owner = trashMember(1, 'owner');
    $role = trashRole(1, 'Kế toán', 20, ['entries.view']);
    trashDelete($owner, $role);

    $this->artisan('roles:purge-trash', ['--days' => $days])->assertFailed();

    expect(trashRow($role)?->trashed())->toBeTrue();
})->with(['abc', '', '-1', '1.5']);

it('checks the role as it is when the delete takes it, not as the actor first read it', function (): void {
    $lead = User::factory()->create();
    $leadRole = trashRole(1, 'Trưởng nhóm', 60, ['roles.manage', 'entries.view']);
    trashIn(1, fn () => $lead->assignRole($leadRole));
    $role = trashRole(1, 'Kế toán', 40, ['entries.view']);

    // read at level 40; a manager above the lead raises it to 70 before the delete takes the row
    trashIn(1, fn () => Role::query()->whereKey($role->getKey())->update(['level' => 70]));

    expect(fn () => trashDelete($lead, $role))->toThrow(AuthorizationException::class)
        ->and(trashRow($role)?->trashed())->toBeFalse();
});

it('stores on restore exactly the permissions it records in the audit', function (): void {
    // held without the permission its one grant implies (stored before that rule existed)
    $role = trashRole(1, 'Kế toán', 40, ['entries.create']);
    $owner = trashMember(1, 'owner');
    trashDelete($owner, $role);

    $back = trashRestore($owner, $role);
    $audited = trashAudit(1, 'rbac.role.restored')?->meta['permissions'] ?? null;

    expect($audited)->toBeArray()->toContain('entries.view')
        ->and(collect($back->fresh()?->permissions->pluck('name'))->sort()->values()->all())
        ->toBe(collect($audited)->sort()->values()->all());
});
