<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * C6 (contract phase) — sites.default_locale is dropped; site_locales.is_default is the sole
 * source of truth. up() removes the column; down() re-adds it AND backfills each site from its
 * is_default locale (0-default → 'vi'), so the drop is reversible without data loss. The
 * backfill is the easy-to-get-wrong part, so it is asserted both directions.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate site_locales, domains, sites, tenants restart identity cascade');
});

it('has dropped sites.default_locale', function (): void {
    expect(Schema::hasColumn('sites', 'default_locale'))->toBeFalse();
});

it('down() re-adds default_locale and backfills it from the is_default locale', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/0007_01_01_000000_drop_sites_default_locale.php');

    $admin = DB::connection('pgsql_admin');
    $admin->table('tenants')->insert(['ulid' => (string) Str::ulid(), 'name' => 'T', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $admin->table('sites')->insert([
        ['ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'EN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'VI', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['ulid' => (string) Str::ulid(), 'tenant_id' => 1, 'name' => 'NONE', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $admin->table('site_locales')->insert([
        ['site_id' => 1, 'locale' => 'en', 'is_default' => true, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'locale' => 'vi', 'is_default' => true, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 3, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],   // no default → fallback
    ]);

    // Migrations run on the privileged connection (ALTER TABLE needs table ownership; the
    // restricted app_user cannot). Capture, then RESTORE the dropped baseline before asserting
    // so a failing assertion never leaves the column behind for the rest of the suite.
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();   // re-add column + backfill (throws red if the column still exists)
        $added = Schema::hasColumn('sites', 'default_locale');
        $en = $admin->table('sites')->where('id', 1)->value('default_locale');
        $vi = $admin->table('sites')->where('id', 2)->value('default_locale');
        $none = $admin->table('sites')->where('id', 3)->value('default_locale');
        $migration->up();
    } finally {
        DB::setDefaultConnection($original);
    }

    expect($added)->toBeTrue()
        ->and($en)->toBe('en')      // is_default = en → 'en' (not the column default)
        ->and($vi)->toBe('vi')
        ->and($none)->toBe('vi');   // no is_default → fallback to the column default
});
