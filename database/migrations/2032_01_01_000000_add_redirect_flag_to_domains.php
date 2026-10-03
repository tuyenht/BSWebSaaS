<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One of each name's two spellings answers, the other only redirects to it (design.md §28, owner
 * 03/10): `shop.example.com` and `www.shop.example.com` are a pair, the owner picks which one runs
 * (default: the one without `www`), and the other answers 301 to it — admin included.
 *
 *   - is_redirect marks the spelling that only redirects. It is never a site's primary domain and
 *     never a platform subdomain (CHECKs).
 *   - Existing pairs get the default: the `www` spelling redirects — unless the `www` spelling is
 *     the site's primary, in which case the other one does.
 *
 * Expand-only and safe in any order with the code: a column with a constant default is added
 * without rewriting the table, and code that does not know the column ignores it. The brief
 * exclusive lock gives up after 5s rather than queueing requests behind it. Re-runnable.
 * down() drops the column and its CHECKs.
 */
return new class extends Migration
{
    /** @var array<string, string> constraint name => CHECK expression */
    private const CHECKS = [
        'domains_redirect_not_primary_check' => 'NOT (is_redirect AND is_primary)',
        'domains_redirect_custom_only_check' => "NOT (is_redirect AND kind <> 'custom')",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE domains ADD COLUMN IF NOT EXISTS is_redirect boolean NOT NULL DEFAULT false');

        // The default for pairs attached before the choice existed: `www.` redirects to the bare
        // name, unless the `www.` spelling is the primary.
        DB::statement(
            "UPDATE domains AS d SET is_redirect = true
             FROM domains AS twin
             WHERE d.kind = 'custom' AND twin.kind = 'custom' AND twin.site_id = d.site_id
               AND NOT d.is_primary AND NOT twin.is_redirect
               AND (
                    (d.hostname LIKE 'www.%' AND twin.hostname = substr(d.hostname, 5))
                 OR (twin.hostname = 'www.' || d.hostname AND twin.is_primary)
               )"
        );

        foreach (self::CHECKS as $name => $expression) {
            if (! $this->hasConstraint($name)) {
                DB::statement("ALTER TABLE domains ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");

        foreach (array_keys(self::CHECKS) as $name) {
            DB::statement("ALTER TABLE domains DROP CONSTRAINT IF EXISTS {$name}");
        }

        DB::statement('ALTER TABLE domains DROP COLUMN IF EXISTS is_redirect');
    }

    private function hasConstraint(string $name): bool
    {
        return DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = 'domains'::regclass",
            [$name],
        ) !== null;
    }
};
