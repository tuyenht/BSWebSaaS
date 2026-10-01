<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * domains carries what attaching a customer's own domain needs (design.md §28): verified_at is the
 * "may be served" marker, HTTPS has its own state, and a site has exactly one primary domain. The
 * rules live in the database so no code path can break them; the migration's backfill of the
 * existing platform subdomains is asserted by running it against rows shaped like the old ones.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** @param array<string, mixed> $overrides */
function insertDomain(array $overrides): void
{
    DB::connection('pgsql_admin')->table('domains')->insert($overrides + [
        'site_id' => 1, 'kind' => 'custom', 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('refuses a second primary domain on one site', function (): void {
    expect(fn () => insertDomain(['hostname' => 'second.example', 'is_primary' => true]))
        ->toThrow(QueryException::class, 'domains_site_id_primary_unique');
});

it('lets each site have its own primary domain', function (): void {
    $primaries = DB::connection('pgsql_admin')->table('domains')->where('is_primary', true)->count();

    expect($primaries)->toBe(2);
});

it('accepts only the known HTTPS states, or none', function (?string $status): void {
    insertDomain(['hostname' => 'shop.example', 'ssl_status' => $status]);

    expect(DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->value('ssl_status'))->toBe($status);
})->with([null, 'pending', 'active', 'failed']);

it('refuses an unknown HTTPS state', function (): void {
    expect(fn () => insertDomain(['hostname' => 'shop.example', 'ssl_status' => 'issued']))
        ->toThrow(QueryException::class, 'domains_ssl_status_check');
});

it('refuses an unknown way of pointing the domain', function (): void {
    expect(fn () => insertDomain(['hostname' => 'shop.example', 'pointing_method' => 'cname']))
        ->toThrow(QueryException::class, 'domains_pointing_method_check');

    insertDomain(['hostname' => 'shop.example', 'pointing_method' => 'alias']);
    insertDomain(['hostname' => 'www.shop.example', 'pointing_method' => 'a_record']);

    expect(DB::connection('pgsql_admin')->table('domains')->whereNotNull('pointing_method')->count())->toBe(2);
});

it('keeps a name live on one site only', function (): void {
    insertDomain(['hostname' => 'shop.example', 'verified_at' => now()]);

    expect(fn () => insertDomain(['site_id' => 2, 'hostname' => 'shop.example', 'verified_at' => now()]))
        ->toThrow(QueryException::class);
});

it('has the columns the daily re-check writes', function (): void {
    expect(Schema::hasColumns('domains', ['checked_at', 'failing_since', 'failure_reason', 'pointing_method']))->toBeTrue();
});

it('stamps existing platform subdomains as verified and leaves customer domains waiting', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2030_01_01_000000_add_verification_and_https_state_to_domains.php');

    $admin = DB::connection('pgsql_admin');

    // Migrations run on the privileged connection (ALTER TABLE needs table ownership). Restore the
    // migrated schema before asserting, so a failing assertion never leaves it rolled back.
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        $rolledBack = ! Schema::hasColumn('domains', 'pointing_method');

        // Rows as they were before this migration: nothing ever wrote verified_at.
        $admin->table('domains')->update(['verified_at' => null]);
        $admin->table('domains')->insert([
            'site_id' => 1, 'hostname' => 'waiting.example', 'kind' => 'custom', 'is_primary' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();
        $migration->up();   // re-runnable: a second run changes nothing and does not fail
    } finally {
        DB::setDefaultConnection($original);
    }

    $subdomain = $admin->table('domains')->where('hostname', 'a.test')->first();
    $custom = $admin->table('domains')->where('hostname', 'waiting.example')->first();

    expect($rolledBack)->toBeTrue()
        ->and($subdomain->verified_at)->not->toBeNull()
        ->and($subdomain->verified_at)->toBe($subdomain->created_at)
        ->and($custom->verified_at)->toBeNull()
        ->and(Schema::hasColumn('domains', 'pointing_method'))->toBeTrue();
});
