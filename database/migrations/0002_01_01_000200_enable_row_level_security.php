<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Gate #1 — RLS, "lưới an toàn số 1" (design.md §5). Applied to the tenant_probes
 * canary; every future tenant table gets the same treatment.
 *
 * Diamond++ hardening:
 *  - FORCE ROW LEVEL SECURITY: the table owner (bsweb) bypasses RLS by default →
 *    without FORCE, isolation is fake. FORCE subjects the owner to the policy too.
 *  - FOR ALL with USING (read/filter) AND WITH CHECK (write): a row cannot be
 *    inserted/updated into another site.
 *  - FAIL-CLOSED: when app.current_site_id is unset, NULLIF(...,'')::bigint is NULL
 *    → site_id = NULL is unknown → ZERO rows (never "all rows").
 *  - Soft-delete (deleted_at) is deliberately NOT in the policy: the Trash view must
 *    still see soft-deleted rows. deleted_at filtering belongs to the Eloquent scope.
 */
return new class extends Migration
{
    private string $table = 'tenant_probes';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE {$this->table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$this->table} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY {$this->table}_site_isolation ON {$this->table}
                FOR ALL
                USING      (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
                WITH CHECK (site_id = NULLIF(current_setting('app.current_site_id', true), '')::bigint)
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("DROP POLICY IF EXISTS {$this->table}_site_isolation ON {$this->table}");
        DB::statement("ALTER TABLE {$this->table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$this->table} DISABLE ROW LEVEL SECURITY");
    }
};
