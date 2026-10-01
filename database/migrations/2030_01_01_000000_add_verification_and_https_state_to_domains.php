<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Room for customers attaching their own domain (design.md §28): a custom domain is served and
 * issued a certificate only once it is verified, HTTPS is a step of its own per domain, and a
 * site has exactly one primary domain.
 *
 *   - verified_at becomes the single "may be served" marker. Platform subdomains are verified by
 *     construction, so every existing one is stamped with its creation time.
 *   - ssl_status is NULL (HTTPS not turned on) or one of pending / active / failed (D13).
 *   - checked_at / failing_since / failure_reason hold the daily re-check: when the domain was last
 *     looked at, since when it has been failing, and why (a reason code the screens translate).
 *   - pointing_method records how the customer pointed the domain: `alias` follows the platform's
 *     target host by name, `a_record` copies its IP — the rows to warn before a server move.
 *   - UNIQUE (site_id) WHERE is_primary: one primary per site.
 *   - UNIQUE (hostname) WHERE verified_at IS NOT NULL: a name is live on one site only; it is also
 *     the index the host → site lookup uses.
 *   - UNIQUE (site_id, hostname): a site lists a name once. It leads with site_id, so it replaces
 *     domains_site_id_index for the sites → domains foreign key and the per-site listing.
 *
 * Expand-only: the original UNIQUE (hostname) stays until the code that lets two sites wait for
 * the same name ships. domains is a GLOBAL table (D6) of a few rows per site — no partitioning.
 *
 * CONCURRENTLY forbids a transaction, so the whole migration is re-runnable after a mid-run
 * failure: IF [NOT] EXISTS throughout, and an index an interrupted build left INVALID is dropped
 * and built again. A unique build fails loudly if the data breaks the rule (two primaries on one
 * site) and changes nothing else. down() drops what up() added; the verified_at stamps stay.
 */
return new class extends Migration
{
    /** CREATE / DROP INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** @var array<string, string> index name => definition */
    private const UNIQUE_INDEXES = [
        'domains_site_id_hostname_unique' => 'domains (site_id, hostname)',
        'domains_hostname_verified_unique' => 'domains (hostname) WHERE verified_at IS NOT NULL',
        'domains_site_id_primary_unique' => 'domains (site_id) WHERE is_primary',
    ];

    /** @var array<string, string> constraint name => CHECK expression */
    private const CHECKS = [
        'domains_ssl_status_check' => "ssl_status IS NULL OR ssl_status IN ('pending','active','failed')",
        'domains_pointing_method_check' => "pointing_method IS NULL OR pointing_method IN ('alias','a_record')",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Every request reads domains, so the brief exclusive locks give up after 5s instead of
        // queueing requests behind them. SET LOCAL needs the transaction.
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement(
                'ALTER TABLE domains
                    ADD COLUMN IF NOT EXISTS checked_at timestamptz NULL,
                    ADD COLUMN IF NOT EXISTS failing_since timestamptz NULL,
                    ADD COLUMN IF NOT EXISTS failure_reason varchar(64) NULL,
                    ADD COLUMN IF NOT EXISTS pointing_method varchar(16) NULL'
            );

            DB::statement(
                "UPDATE domains SET verified_at = COALESCE(created_at, now())
                 WHERE kind = 'subdomain' AND verified_at IS NULL"
            );

            foreach (self::CHECKS as $name => $expression) {
                if (! $this->hasConstraint($name)) {
                    DB::statement("ALTER TABLE domains ADD CONSTRAINT {$name} CHECK ({$expression}) NOT VALID");
                }
            }
        });

        foreach (array_keys(self::CHECKS) as $name) {
            DB::statement("ALTER TABLE domains VALIDATE CONSTRAINT {$name}");
        }

        foreach (self::UNIQUE_INDEXES as $index => $definition) {
            $this->dropIfInvalid($index);
            DB::statement("CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON {$definition}");
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS domains_site_id_index');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->dropIfInvalid('domains_site_id_index');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS domains_site_id_index ON domains (site_id)');

        foreach (array_keys(self::UNIQUE_INDEXES) as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");

            foreach (array_keys(self::CHECKS) as $name) {
                DB::statement("ALTER TABLE domains DROP CONSTRAINT IF EXISTS {$name}");
            }

            DB::statement(
                'ALTER TABLE domains
                    DROP COLUMN IF EXISTS checked_at,
                    DROP COLUMN IF EXISTS failing_since,
                    DROP COLUMN IF EXISTS failure_reason,
                    DROP COLUMN IF EXISTS pointing_method'
            );
        });
    }

    private function hasConstraint(string $name): bool
    {
        return DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = 'domains'::regclass",
            [$name],
        ) !== null;
    }

    private function dropIfInvalid(string $index): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid',
            [$index],
        );

        if ($invalid !== null) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }
};
