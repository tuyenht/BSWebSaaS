<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Splits the media.delete permission in two: media.delete.own (send to the Trash the files you
 * uploaded and nobody uses yet, restore the ones you trashed) and media.delete.any (every file).
 *
 * Who gets which half: the website's own system roles that run it (owner, admin) and the editor
 * template get media.delete.any — as the shipped templates now say; any other role holding it (a
 * custom role) gets media.delete.any when it can also purge the Trash, otherwise media.delete.own.
 * Members given media.delete directly (not through a role) get media.delete.own. So a custom role
 * without purge, and a direct grant, narrow from every file to their own files: only a website's
 * admin decides who may delete everyone's files. rbac:sync, run on every deploy, then gives the
 * author template media.delete.own.
 *
 * Expand-only: media.delete itself stays, with its grants, so code rolled back to before the split
 * still finds it; nothing reads it any more. Removing it is a later, separate step.
 *
 * down() puts media.delete back only where media.delete.any is held (a role made after the split has
 * only the halves), never for media.delete.own: the code before the split reads media.delete as
 * «every file», so giving it to own-only holders would widen them. Grants of media.delete kept from
 * before the split stay. Then it removes the two halves; their grants go with them (CASCADE).
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';
        $id = fn (string $name): int => is_numeric($v = DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id')) ? (int) $v : 0;
        foreach (['media.delete.own', 'media.delete.any'] as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);
        }
        $old = $id('media.delete');
        $own = $id('media.delete.own');
        $any = $id('media.delete.any');
        $purge = $id('media.purge');

        if ($old !== 0) {
            // the roles that hold media.delete, and which half each one gets
            DB::statement(<<<'SQL'
                INSERT INTO role_has_permissions (permission_id, role_id)
                SELECT CASE WHEN (r.is_system AND r.name IN ('owner', 'admin', 'editor'))
                                 OR EXISTS (SELECT 1 FROM role_has_permissions p WHERE p.role_id = r.id AND p.permission_id = ?::bigint)
                            THEN ?::bigint ELSE ?::bigint END,
                       r.id
                FROM roles r
                JOIN role_has_permissions h ON h.role_id = r.id AND h.permission_id = ?::bigint
                ON CONFLICT DO NOTHING
                SQL, [$purge, $any, $own, $old]);
            DB::statement(<<<'SQL'
                INSERT INTO model_has_permissions (permission_id, model_type, model_id, site_id)
                SELECT ?::bigint, model_type, model_id, site_id FROM model_has_permissions WHERE permission_id = ?::bigint
                ON CONFLICT DO NOTHING
                SQL, [$own, $old]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = 'web';
        DB::table('permissions')->insertOrIgnore(['name' => 'media.delete', 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);
        $oldId = DB::table('permissions')->where('name', 'media.delete')->where('guard_name', $guard)->value('id');
        $old = is_numeric($oldId) ? (int) $oldId : 0;
        $anyId = DB::table('permissions')->where('name', 'media.delete.any')->where('guard_name', $guard)->value('id');
        if (is_numeric($anyId)) {
            DB::statement('INSERT INTO role_has_permissions (permission_id, role_id) SELECT ?::bigint, role_id FROM role_has_permissions WHERE permission_id = ?::bigint ON CONFLICT DO NOTHING',
                [$old, (int) $anyId]);
            DB::statement('INSERT INTO model_has_permissions (permission_id, model_type, model_id, site_id) SELECT ?::bigint, model_type, model_id, site_id FROM model_has_permissions WHERE permission_id = ?::bigint ON CONFLICT DO NOTHING',
                [$old, (int) $anyId]);
        }
        DB::table('permissions')->whereIn('name', ['media.delete.own', 'media.delete.any'])->where('guard_name', $guard)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
