<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The post categories an invitation limits its new member to, copied to member_category_scopes when
 * the invitation is accepted (in the same transaction). No row for an invitation = every category,
 * as for a member. The same shape and rules as member_category_scopes:
 *
 *  - site_id CASCADE (D4); (site_id, invitation_id) → invitations (site_id, id) CASCADE pins each row
 *    to its invitation's site and lets it go with the invitation, as invitation_roles does.
 *  - taxonomy_id ON DELETE RESTRICT — the same deliberate fail-closed guard as on members: if
 *    deleting a category silently dropped the row, an invitation limited to "Tin tức" would join
 *    as "every category". Deleting a scoped category moves the rows onto the merge target or is
 *    refused (DeleteTaxonomy); the database refuses the rest.
 *  - UNIQUE (site_id, invitation_id, taxonomy_id) serves the per-invitation read, the composite
 *    foreign key's parent-delete lookup and the site_id foreign key; (taxonomy_id) serves the
 *    RESTRICT lookup when a category is deleted (it carries no site_id and bypasses RLS).
 *
 * A new, empty table: its indexes are built with it (CONCURRENTLY is for existing tables). Small
 * (a few rows per limited invitation), never partitioned. site_id + RLS FORCE fail-closed.
 * down() drops the table: invitations not yet accepted join with every category again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_category_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedBigInteger('invitation_id');
            $table->foreignId('taxonomy_id')->constrained('taxonomies')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['site_id', 'invitation_id'])->references(['site_id', 'id'])->on('invitations')->cascadeOnDelete();
            $table->unique(['site_id', 'invitation_id', 'taxonomy_id']);
            $table->index('taxonomy_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE invitation_category_scopes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE invitation_category_scopes FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY invitation_category_scopes_site_isolation ON invitation_category_scopes
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_category_scopes');
    }
};
