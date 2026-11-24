<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission to download the files visitors send with a lead (`forms.files`, «Tải tệp đính
 * kèm»). It is given at first to every role that holds forms.manage, the people who already handle
 * the website's leads: the system roles that run a website (owner, admin, editor) and any role of
 * the website's own that manages leads. A role that only reads leads (the «Chỉ xem» role and any
 * like it) does not get it: a CV or a drawing is more than the contact line it came with, and the
 * website's admin can still give it in «Nhóm quyền». rbac:sync, run on every deploy, gives it to
 * the system roles of websites made later, from the templates.
 *
 * Expand-only: nothing else changes. down() removes the permission; its grants go with it (CASCADE).
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';
        DB::table('permissions')->insertOrIgnore(['name' => 'forms.files', 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);
        $files = DB::table('permissions')->where('name', 'forms.files')->where('guard_name', $guard)->value('id');
        $manage = DB::table('permissions')->where('name', 'forms.manage')->where('guard_name', $guard)->value('id');
        if (is_numeric($files) && is_numeric($manage)) {
            DB::statement(<<<'SQL'
                INSERT INTO role_has_permissions (permission_id, role_id)
                SELECT ?::bigint, role_id FROM role_has_permissions WHERE permission_id = ?::bigint
                ON CONFLICT DO NOTHING
                SQL, [(int) $files, (int) $manage]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'forms.files')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
