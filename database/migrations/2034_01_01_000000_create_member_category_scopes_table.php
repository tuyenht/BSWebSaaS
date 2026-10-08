<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The categories a member may write in, per site. No row for a member on a site = every category
 * (the default, and what every member had before this table). A row covers its category and every
 * category below it (ltree). Site owners, admins (role level ≥ 70) and platform super_admins are
 * never limited, whatever rows exist.
 *
 *  - site_id, user_id CASCADE (D4): the rows go with the site or the account.
 *  - taxonomy_id ON DELETE RESTRICT — a deliberate fail-closed guard, a departure from D4's SET
 *    NULL for soft references: if a member's last scoped category vanished silently they would go
 *    from "only these categories" to "every category". Deleting a scoped category must therefore
 *    decide where the rows go (the merge target) or be refused; the database refuses the rest.
 *  - UNIQUE (site_id, user_id, taxonomy_id) also serves the per-request read by (site_id, user_id)
 *    and the site_id foreign key; (taxonomy_id) serves the RESTRICT lookup when a category is
 *    deleted (it carries no site_id and bypasses RLS); (user_id) the same for deleting an account.
 *
 * Small (a few rows per limited member), never partitioned. site_id + RLS FORCE fail-closed.
 * down() drops the table: every member is back to every category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_category_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('taxonomy_id')->constrained('taxonomies')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['site_id', 'user_id', 'taxonomy_id']);
            $table->index('taxonomy_id');
            $table->index('user_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE member_category_scopes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE member_category_scopes FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY member_category_scopes_site_isolation ON member_category_scopes
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('member_category_scopes');
    }
};
