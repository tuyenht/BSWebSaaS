<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What entries:unpublish-due and products:unpublish-due read every minute: per site, the live rows
 * whose expires_at has come (`site_id = ? AND status = 'published' AND expires_at <= now()`, out of
 * the Trash). Partial on exactly those rows, so each index holds only the few items that carry an end
 * time and are still live; an item leaves it as it is taken down. Led by site_id (the sweep runs per
 * site, ADR-18). No list reads expires_at: the lists keep reading `status`, so no other index changes.
 *
 * Then the CHECK constraints the previous migration added NOT VALID are validated: a scan that takes
 * a SHARE UPDATE EXCLUSIVE lock, so writes go on meanwhile.
 *
 * CONCURRENTLY forbids a transaction, so a mid-run failure must be safely re-runnable: an index an
 * interrupted build left INVALID is dropped and built again (IF NOT EXISTS alone would keep it).
 * down() drops the indexes; the constraints stay (the previous migration's down drops them).
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, array{0: string, 1: string}> index => [table, constraint validated after it] */
    private const array INDEXES = [
        'entries_expiry_due_idx' => ['entries', 'entries_expires_after_publish'],
        'products_expiry_due_idx' => ['products', 'products_expires_after_publish'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index => [$table, $check]) {
            $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [$index]);
            if ($invalid !== null) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
            }

            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON {$table} (site_id, expires_at) WHERE status = 'published' AND expires_at IS NOT NULL AND deleted_at IS NULL");
        }

        foreach (self::INDEXES as [$table, $check]) {
            DB::transaction(function () use ($table, $check): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$check}");
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::INDEXES)) as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }
};
