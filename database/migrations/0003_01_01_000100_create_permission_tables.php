<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * RBAC tables (spatie/laravel-permission + teams). GLOBAL authz metadata (D6: no
 * RLS): role/permission resolution happens during auth, BEFORE any tenant tx
 * context, so these are NOT RLS-protected — they are scoped IN-APP by the spatie
 * team key (= site_id). See RlsCoverageTest for the documented exception.
 *
 * Team key = site_id (design.md §15). PLATFORM scope uses sentinel site_id = 0
 * (never a real site — identity starts at 1), because spatie makes the team key
 * part of the model_has_* primary key and therefore NOT NULL.
 *
 * Names are hardcoded (not read from config) to keep the migration type-safe and
 * match config/permission.php (table_names defaults + team_foreign_key = site_id).
 * Local additions to the published stub: roles.level + roles.is_system + timestamptz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestampsTz();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->index('site_id', 'roles_site_id_index');
            $table->string('name');
            $table->string('guard_name');
            // Local: hierarchy (only grant roles below your own level) + system flag.
            $table->integer('level')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->unique(['site_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();

            $table->unsignedBigInteger('site_id');
            $table->index('site_id', 'model_has_permissions_site_id_index');
            $table->primary(
                ['site_id', 'permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary',
            );
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();

            $table->unsignedBigInteger('site_id');
            $table->index('site_id', 'model_has_roles_site_id_index');
            $table->primary(
                ['site_id', 'role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary',
            );
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
