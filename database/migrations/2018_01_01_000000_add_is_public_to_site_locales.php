<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `site_locales.enabled` has carried two meanings: the languages editors write in (admin) and
 * the languages visitors can open (storefront prefix, hreflang, sitemap). A site could not prepare
 * a translation before showing it. `is_public` takes the visitor half: a locale is authored when
 * enabled and shown when also public. Every existing row gets exactly the visibility it has today:
 * an enabled locale is public, a switched-off one is not — the column arrives with a constant
 * default (a catalog-only change in PostgreSQL, no table rewrite), then the few switched-off rows
 * are turned private before the CHECK below would refuse them.
 *
 * Two CHECKs pin what the storefront relies on: the default locale is always enabled and public
 * (its URLs are the canonical unprefixed ones), and only an enabled locale can be public. Each is
 * added NOT VALID and validated in its own statement (no wrapping transaction), so the validating
 * scan holds no lock that blocks reads or writes; every step is guarded, so a run that stops half
 * way can simply be repeated. A default locale that is already disabled would fail validation, so
 * it is reported by site first instead of surfacing as a bare constraint error.
 */
return new class extends Migration
{
    /** Each statement commits on its own so VALIDATE CONSTRAINT runs under its weaker lock. */
    public $withinTransaction = false;

    public function up(): void
    {
        $broken = DB::table('site_locales')->where('is_default', true)->where('enabled', false)->pluck('site_id');
        if ($broken->isNotEmpty()) {
            throw new RuntimeException(
                'site_locales: the default locale is disabled for site(s) '.$broken->implode(', ')
                .'. Re-enable it before running this migration.'
            );
        }

        DB::statement('ALTER TABLE site_locales ADD COLUMN IF NOT EXISTS is_public boolean NOT NULL DEFAULT true');

        // A locale switched off before this column existed was hidden from visitors too — keep it so.
        DB::table('site_locales')->where('enabled', false)->where('is_public', true)->update(['is_public' => false]);

        $this->addCheck(
            'site_locales_default_visible_check',
            'CHECK (NOT is_default OR (enabled AND is_public))',
        );
        $this->addCheck(
            'site_locales_public_requires_enabled_check',
            'CHECK (NOT is_public OR enabled)',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE site_locales DROP CONSTRAINT IF EXISTS site_locales_public_requires_enabled_check');
        DB::statement('ALTER TABLE site_locales DROP CONSTRAINT IF EXISTS site_locales_default_visible_check');
        DB::statement('ALTER TABLE site_locales DROP COLUMN IF EXISTS is_public');
    }

    private function addCheck(string $name, string $check): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = to_regclass(?)',
            [$name, 'site_locales'],
        );

        if ($exists === null) {
            DB::statement("ALTER TABLE site_locales ADD CONSTRAINT {$name} {$check} NOT VALID");
        }

        DB::statement("ALTER TABLE site_locales VALIDATE CONSTRAINT {$name}");
    }
};
