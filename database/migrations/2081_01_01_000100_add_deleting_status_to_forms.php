<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A form status for «Xóa» on a form with more leads than one web request deletes (config
 * forms.delete_inline_max_leads): `deleting`. Such a form takes no lead (the storefront reads only
 * active forms), reads as gone on every screen (Form's global scope) and has its leads deleted in
 * batches by a queued job (DeleteLargeForm), which then deletes the form row.
 *
 * The status CHECK (D13) is swapped for one listing the new status: dropped and re-added NOT VALID,
 * then validated — the house pattern for a CHECK on an existing table. Both run in the migration's
 * one transaction; forms is small (at most a few dozen rows a site), so the validating scan is
 * short, and taking the lock gives up after 5s rather than queue lead sends behind it. Re-runnable
 * after a mid-run failure (DROP IF EXISTS). No row is rewritten.
 *
 * down() refuses while forms are being deleted (it says how many): they would break the old CHECK,
 * and a rollback waits for their jobs to finish rather than bring them back.
 */
return new class extends Migration
{
    private const array STATUSES = ['active', 'disabled'];

    private const string ADDED = 'deleting';

    public function up(): void
    {
        $this->listStatuses([...self::STATUSES, self::ADDED]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $left = DB::table('forms')->where('status', self::ADDED)->count();
        if ($left > 0) {
            throw new RuntimeException("{$left} forms are still being deleted: wait for their jobs to finish, then roll back.");
        }
        $this->listStatuses(self::STATUSES);
    }

    /** @param list<string> $statuses */
    private function listStatuses(array $statuses): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $list = implode(', ', array_map(fn (string $status): string => "'{$status}'", $statuses));

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS forms_status_check');
        DB::statement("ALTER TABLE forms ADD CONSTRAINT forms_status_check CHECK (status IN ({$list})) NOT VALID");
        DB::statement('ALTER TABLE forms VALIDATE CONSTRAINT forms_status_check');
    }
};
