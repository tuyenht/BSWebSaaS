<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\AssignRole;
use App\Modules\Rbac\Actions\CreateCustomRole;
use App\Modules\Rbac\Actions\DeleteCustomRole;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Actions\RestoreCustomRole;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\CustomRoleRules;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * A custom role's name is unique on its website whatever its case, and a website keeps at most 50
 * custom roles. The database's unique is case-sensitive and cannot count, so both rules are checked
 * in PHP — under a per-site advisory lock taken inside the write's transaction, before the checks.
 * Two saves racing on one website therefore run one after the other: the second waits for the
 * first, then sees its row and is refused.
 *
 * PHP runs one statement at a time, so the race is staged on two real connections: the first
 * write runs on pgsql_admin and stays uncommitted while the second runs on the app connection with
 * a short lock_timeout. Without the lock the second would not see the first's row and both «Nhóm A»
 * and «nhóm a» would be stored; with it the second waits and times out (55P03), and once the first
 * commits it is refused.
 *
 * A grant racing a delete into the trash is the same shape on a row lock: the delete holds the
 * role's row while it checks that nobody holds it, and the grant reads the role under a share lock
 * before it writes — so it waits, then finds the role in the trash.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    DB::connection('pgsql_admin')->statement('truncate invitations, invitation_roles, audit_logs, users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();

    foreach ([1, 2] as $site) {
        DB::connection('pgsql_admin')->table('sites')->where('id', $site)
            ->update(['site_type_id' => DB::table('site_types')->where('key', 'corporate')->value('id')]);
        app(Tenancy::class)->enter($site, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.custom_roles', 'value' => true]));
    }
    $this->owner = roleRaceOwner(1);
});

function roleRaceOwner(int $site, string $template = 'owner'): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$template];
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, function () use ($template, $tpl, $user): void {
        $role = Role::findOrCreate($template, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions']);
        $user->assignRole($role);
    });

    return $user;
}

/** The custom role names of $site, committed (read past every scope). */
function roleRaceNames(int $site = 1): array
{
    return DB::connection('pgsql_admin')->table('roles')->where('site_id', $site)->where('is_system', false)
        ->whereNull('deleted_at')->orderBy('id')->pluck('name')->all();
}

/** Run $write on the app connection in $site, giving up on a lock quickly; the refusal, the SQLSTATE, or null. */
function roleRaceOnApp(Closure $write, int $site = 1): ?string
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql');

    try {
        return app(Tenancy::class)->enter($site, function () use ($write): ?string {
            DB::statement("set local lock_timeout = '300ms'");
            try {
                $write();
            } catch (RoleChangeRejectedException $e) {
                return $e->reason;
            } catch (AuthorizationException $e) {
                return 'denied: '.$e->getMessage();
            } catch (QueryException $e) {
                return (string) $e->getCode();
            }

            return null;
        });
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/** Run $first on pgsql_admin in site 1, uncommitted, then $second on the app connection in $secondSite; what the second got. */
function roleRaceWhileFirstOpen(Closure $first, Closure $second, int $secondSite = 1): ?string
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        return app(Tenancy::class)->enter(1, function () use ($first, $second, $secondSite): ?string {
            $first();

            return roleRaceOnApp($second, $secondSite);
        });
    } finally {
        DB::setDefaultConnection($previous);
    }
}

it('lets exactly one of two creates whose names differ only by case through', function (): void {
    /** @var User $owner */
    $owner = $this->owner;
    $onAdmin = User::on('pgsql_admin')->findOrFail($owner->id);

    $whileOpen = roleRaceWhileFirstOpen(
        fn () => app(CreateCustomRole::class)->handle($onAdmin, 'Nhóm A', 20, ['entries.view']),
        fn () => app(CreateCustomRole::class)->handle($owner->fresh(), 'nhóm a', 20, ['entries.view']),
    );
    $retry = roleRaceOnApp(fn () => app(CreateCustomRole::class)->handle($owner->fresh(), 'nhóm a', 20, ['entries.view']));

    expect($whileOpen)->toBe('55P03')
        ->and($retry)->toBe('name_taken')
        ->and(roleRaceNames())->toBe(['Nhóm A']);
});

it('lets exactly one of two creates take the 50th place', function (): void {
    /** @var User $owner */
    $owner = $this->owner;
    $rows = [];
    for ($i = 1; $i < CustomRoleRules::MAX_CUSTOM_ROLES; $i++) {
        $rows[] = ['site_id' => 1, 'name' => "Có sẵn {$i}", 'guard_name' => PermissionRegistry::GUARD, 'level' => 10, 'is_system' => false, 'created_at' => now(), 'updated_at' => now()];
    }
    DB::connection('pgsql_admin')->table('roles')->insert($rows);
    $onAdmin = User::on('pgsql_admin')->findOrFail($owner->id);

    $whileOpen = roleRaceWhileFirstOpen(
        fn () => app(CreateCustomRole::class)->handle($onAdmin, 'Thứ nhất', 20, ['entries.view']),
        fn () => app(CreateCustomRole::class)->handle($owner->fresh(), 'Thứ hai', 20, ['entries.view']),
    );
    $retry = roleRaceOnApp(fn () => app(CreateCustomRole::class)->handle($owner->fresh(), 'Thứ hai', 20, ['entries.view']));

    expect($whileOpen)->toBe('55P03')
        ->and($retry)->toBe('too_many')
        ->and(count(roleRaceNames()))->toBe(CustomRoleRules::MAX_CUSTOM_ROLES);
});

it('makes a restore wait for a create of the same name, then refuses it', function (): void {
    /** @var User $owner */
    $owner = $this->owner;
    $trashed = app(Tenancy::class)->enter(1, function (): Role {
        $role = Role::findOrCreate('Kế toán', PermissionRegistry::GUARD);
        $role->forceFill(['level' => 20, 'is_system' => false])->save();
        $role->delete();

        return $role;
    });
    $onAdmin = User::on('pgsql_admin')->findOrFail($owner->id);

    $whileOpen = roleRaceWhileFirstOpen(
        fn () => app(CreateCustomRole::class)->handle($onAdmin, 'KẾ TOÁN', 20, ['entries.view']),
        fn () => app(RestoreCustomRole::class)->handle($owner->fresh(), $trashed),
    );
    $retry = roleRaceOnApp(fn () => app(RestoreCustomRole::class)->handle($owner->fresh(), $trashed));

    expect($whileOpen)->toBe('55P03')
        ->and($retry)->toBe('restore_name_taken')
        ->and(roleRaceNames())->toBe(['KẾ TOÁN']);
});

it('does not hold up a create on another website', function (): void {
    /** @var User $owner */
    $owner = $this->owner;
    $other = roleRaceOwner(2);
    $onAdmin = User::on('pgsql_admin')->findOrFail($owner->id);

    $whileOpen = roleRaceWhileFirstOpen(
        fn () => app(CreateCustomRole::class)->handle($onAdmin, 'Nhóm A', 20, ['entries.view']),
        fn () => app(CreateCustomRole::class)->handle($other->fresh(), 'Nhóm A', 20, ['entries.view']),
        secondSite: 2,
    );

    expect($whileOpen)->toBeNull()
        ->and(roleRaceNames(2))->toBe(['Nhóm A']);
});

it('makes a grant wait for a delete of the role into the trash, then refuses it', function (string $path): void {
    /** @var User $owner */
    $owner = $this->owner;
    $member = roleRaceOwner(1, 'viewer');
    $role = app(Tenancy::class)->enter(1, fn (): Role => app(CreateCustomRole::class)->handle($owner->fresh(), 'Kế toán', 20, ['entries.view']));
    $role->load('permissions');
    $onAdmin = User::on('pgsql_admin')->findOrFail($owner->id);
    $grant = fn () => match ($path) {
        'assign' => app(AssignRole::class)->handle($owner->fresh(), $member->fresh(), $role),
        'invite' => app(InviteMember::class)->handle($owner->fresh(), 'moi@example.test', [$role]),
    };

    $whileOpen = roleRaceWhileFirstOpen(fn () => app(DeleteCustomRole::class)->handle($onAdmin, $role), $grant);
    $retry = roleRaceOnApp($grant);

    expect($whileOpen)->toBe('55P03')
        ->and($retry)->toContain('deleted meanwhile')
        ->and(roleRaceNames())->toBe([])
        ->and(DB::connection('pgsql_admin')->table('model_has_roles')->where('role_id', $role->getKey())->exists())->toBeFalse()
        ->and(DB::connection('pgsql_admin')->table('invitation_roles')->where('role_id', $role->getKey())->exists())->toBeFalse();
})->with(['assign', 'invite']);
