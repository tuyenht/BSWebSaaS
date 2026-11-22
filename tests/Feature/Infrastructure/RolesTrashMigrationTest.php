<?php

use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The migration that gives custom roles a trash. up(): a deleted_at column, one LIVE role per
 * (site, name, guard) through a partial unique index, the old full unique constraint gone, and a
 * partial index for the trash list and the purge. down() refuses while the trash holds a role (the
 * full unique could not hold a live and a trashed role of one name), otherwise undoes it all; up()
 * runs again on top of it, and twice in a row, as after a half-finished deploy.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    app(TeamContext::class)->forget();
});

function rolesTrashMigShape(): array
{
    $db = DB::connection('pgsql_admin');

    return [
        'column' => $db->selectOne("select data_type from information_schema.columns where table_name = 'roles' and column_name = 'deleted_at'")?->data_type,
        'indexes' => collect($db->select("select indexname, indexdef from pg_indexes where tablename = 'roles' order by indexname"))->mapWithKeys(fn ($r): array => [$r->indexname => $r->indexdef])->all(),
        'constraints' => collect($db->select("select conname from pg_constraint where conrelid = 'roles'::regclass and contype = 'u' order by conname"))->pluck('conname')->all(),
        'invalid' => $db->selectOne("select count(*) as n from pg_index i join pg_class c on c.oid = i.indrelid where c.relname = 'roles' and not i.indisvalid")->n,
    ];
}

function rolesTrashMigRole(string $name, ?string $deletedAt = null): void
{
    DB::connection('pgsql_admin')->table('roles')->insert(array_filter([
        'site_id' => 1, 'name' => $name, 'guard_name' => PermissionRegistry::GUARD, 'level' => 10, 'is_system' => false,
        'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $deletedAt,
    ], fn ($v): bool => $v !== null));
}

it('rolls the roles trash back and forward on a real database', function (): void {
    $migration = require base_path('database/migrations/2088_01_01_000000_add_trash_to_roles.php');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        $after = rolesTrashMigShape();
        expect($after['column'])->toBe('timestamp with time zone')
            ->and($after['indexes'])->toHaveKeys(['roles_site_id_name_guard_name_live_unique', 'roles_site_id_deleted_at_index'])
            ->and($after['constraints'])->not->toContain('roles_site_id_name_guard_name_unique')
            ->and($after['invalid'])->toBe(0);

        // live + trashed with the same name: allowed by the partial unique; two live: refused
        rolesTrashMigRole('Kế toán');
        rolesTrashMigRole('Kế toán', now()->toIso8601String());
        expect(fn () => rolesTrashMigRole('Kế toán'))->toThrow(QueryException::class, 'roles_site_id_name_guard_name_live_unique');

        // down() refuses while the trash holds a role, and changes nothing
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'the trash still holds roles');
        expect(rolesTrashMigShape())->toBe($after);

        DB::table('roles')->whereNotNull('deleted_at')->delete();
        $migration->down();
        $before = rolesTrashMigShape();
        expect($before['column'])->toBeNull()
            ->and($before['indexes'])->not->toHaveKey('roles_site_id_name_guard_name_live_unique')
            ->and($before['indexes'])->not->toHaveKey('roles_site_id_deleted_at_index')
            ->and($before['constraints'])->toContain('roles_site_id_name_guard_name_unique')
            ->and($before['invalid'])->toBe(0);
        expect(fn () => rolesTrashMigRole('Kế toán'))->toThrow(QueryException::class, 'roles_site_id_name_guard_name_unique');

        // down() twice is harmless too
        $migration->down();
        expect(rolesTrashMigShape())->toBe($before);

        $migration->up();
        $migration->up();   // re-run, as after a half-finished deploy
        expect(rolesTrashMigShape())->toBe($after);
    } finally {
        // never leave the shared test database without the trash, whatever failed above
        DB::table('roles')->whereNotNull('deleted_at')->delete();
        $migration->up();
        DB::setDefaultConnection($previous);
    }
});
