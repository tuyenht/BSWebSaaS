<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Xóa» on the «Biểu mẫu» list deletes a form together with the leads it took (DeleteForm), so a
 * form row is now hard-deleted. Deleting it makes Postgres find its leads with
 * `DELETE FROM ONLY form_submissions WHERE form_id = $1` (ON DELETE CASCADE): that lookup carries
 * no site_id and RLS does not apply to it, so the (site_id, form_id, created_at) index cannot serve
 * it and every delete would scan every website's leads. This index is led by form_id; form_fields
 * is already served by its unique (form_id, key).
 *
 * form_id is NOT NULL, so the index is not partial. Size (estimated, not measured): about 20 bytes
 * a lead; 100M leads ≈ 2 GB. One more index entry per lead sent through a form.
 * tests/Feature/Infrastructure/ForeignKeyIndexCoverageTest.php flags any foreign key left without one.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * Expand-only; down() drops what up() added and touches no data.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const string INDEX = 'form_submissions_form_id_index';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->dropIfInvalid();

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON form_submissions (form_id)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }

    private function dropIfInvalid(): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [self::INDEX],
        );

        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }
    }
};
