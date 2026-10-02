<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A customer's domain that is merely attached to a site holds no claim (design.md §28): two sites
 * may wait for the same name, and the one whose owner points it — or, when contested, proves it by
 * a TXT record — gets it. The original UNIQUE (hostname) forbade the second waiting row, so it goes.
 *
 * What still holds, from the previous migration: a name is LIVE on one site only
 * (UNIQUE (hostname) WHERE verified_at IS NOT NULL — also the host → site lookup index), and a
 * site lists a name once (UNIQUE (site_id, hostname)).
 *
 * Contract step. Safe to run before or after the code that attaches domains: nothing reads the
 * dropped constraint, and until that code runs every row is a verified platform subdomain. The
 * lock is brief and gives up after 5s rather than queueing requests behind it. Re-runnable.
 * down() restores the constraint, and fails loudly if two sites wait for one name by then.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_hostname_unique');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $exists = DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'domains_hostname_unique' AND conrelid = 'domains'::regclass");

        if ($exists === null) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE domains ADD CONSTRAINT domains_hostname_unique UNIQUE (hostname)');
        }
    }
};
