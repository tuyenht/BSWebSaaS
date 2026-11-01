<?php

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CustomerCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Every customer (tenants row) carries a number of its own, `customer_no`, from a sequence: the
 * customers that existed when it arrived were numbered oldest first, and each new one draws the
 * next number from the column default, so code that inserts customers without knowing the column
 * keeps working. The number is all the database stores; the code staff read ("KH-000123") is
 * built from config, so another brand changes the prefix without touching the data.
 */

const CUSTOMER_NO_MIGRATION = 'migrations/2042_01_01_000000_add_customer_no_to_tenants.php';

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate tenant_probes, domains, sites, tenants restart identity cascade');
});

function cnTenant(string $name, string $createdAt, ?string $deletedAt = null): int
{
    return DB::connection('pgsql_admin')->table('tenants')->insertGetId([
        'ulid' => (string) Str::ulid(), 'name' => $name, 'settings' => '{}',
        'created_at' => $createdAt, 'updated_at' => $createdAt, 'deleted_at' => $deletedAt,
    ]);
}

/** Runs the migration's down(), then $between, then up() — up() runs even when $between fails. */
function cnRerun(Closure $between): void
{
    /** @var Migration $migration */
    $migration = require database_path(CUSTOMER_NO_MIGRATION);

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        try {
            $between();
        } finally {
            $migration->up();
        }
    } finally {
        DB::setDefaultConnection($original);
    }
}

it('numbers the customers that already exist oldest first, ties by id, deleted ones too', function (): void {
    $ids = [];
    cnRerun(function () use (&$ids): void {
        $ids['march'] = cnTenant('March', '2026-03-01 08:00:00+00');
        $ids['january'] = cnTenant('January', '2026-01-15 08:00:00+00');
        $ids['january_too'] = cnTenant('January too', '2026-01-15 08:00:00+00');
        $ids['deleted'] = cnTenant('Deleted', '2026-02-01 08:00:00+00', '2026-02-02 08:00:00+00');
    });

    $numbers = DB::table('tenants')->pluck('customer_no', 'id');

    expect($numbers[$ids['january']])->toBe(1)
        ->and($numbers[$ids['january_too']])->toBe(2)
        ->and($numbers[$ids['deleted']])->toBe(3)
        ->and($numbers[$ids['march']])->toBe(4);
});

it('gives the next customer the number after the last one, even from code that does not know the column', function (): void {
    cnRerun(function (): void {
        cnTenant('Old', '2026-01-01 08:00:00+00');
        cnTenant('Older', '2025-01-01 08:00:00+00');
    });

    // app_user, the role the application runs as, draws from the sequence too.
    DB::table('tenants')->insert(['ulid' => (string) Str::ulid(), 'name' => 'New', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $created = Tenant::query()->create(['ulid' => (string) Str::ulid(), 'name' => 'Newer', 'status' => 'active']);

    expect(DB::table('tenants')->where('name', 'New')->value('customer_no'))->toBe(3)
        ->and(Tenant::query()->findOrFail($created->id)->customer_no)->toBe(4);
});

it('starts at 1 when there were no customers', function (): void {
    cnRerun(fn () => null);

    cnTenant('First', '2026-01-01 08:00:00+00');

    expect(DB::table('tenants')->value('customer_no'))->toBe(1);
});

it('runs again without renumbering anyone', function (): void {
    cnTenant('A', '2026-01-01 08:00:00+00');
    cnTenant('B', '2026-01-02 08:00:00+00');
    $before = DB::table('tenants')->orderBy('id')->pluck('customer_no')->all();

    /** @var Migration $migration */
    $migration = require database_path(CUSTOMER_NO_MIGRATION);
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->up();
    } finally {
        DB::setDefaultConnection($original);
    }

    cnTenant('C', '2026-01-03 08:00:00+00');

    expect(DB::table('tenants')->orderBy('id')->pluck('customer_no')->all())->toBe([...$before, 3]);
});

it('never lets two customers share a number, or one go without', function (): void {
    $first = cnTenant('A', '2026-01-01 08:00:00+00');
    $second = cnTenant('B', '2026-01-02 08:00:00+00');
    $admin = DB::connection('pgsql_admin');

    expect(fn () => $admin->table('tenants')->where('id', $second)->update(['customer_no' => $admin->table('tenants')->where('id', $first)->value('customer_no')]))
        ->toThrow(QueryException::class, 'tenants_customer_no_unique')
        ->and(fn () => $admin->table('tenants')->where('id', $second)->update(['customer_no' => null]))
        ->toThrow(QueryException::class, 'customer_no');
});

it('shows the number as the configured code, never storing the prefix', function (): void {
    config(['customers.code_prefix' => 'KH-', 'customers.code_pad' => 6]);
    cnTenant('A', '2026-01-01 08:00:00+00');
    $tenant = Tenant::query()->firstOrFail();

    expect($tenant->customer_code)->toBe('KH-000001')
        ->and(CustomerCode::format(123))->toBe('KH-000123')
        ->and(CustomerCode::format(1234567))->toBe('KH-1234567');   // longer than the pad: never cut

    config(['customers.code_prefix' => 'CUS', 'customers.code_pad' => 4]);
    expect(CustomerCode::format(42))->toBe('CUS0042')
        ->and(DB::table('tenants')->value('customer_no'))->toBe(1);
});

it('has no code for a customer whose number was not loaded', function (): void {
    cnTenant('A', '2026-01-01 08:00:00+00');

    expect(Tenant::query()->select('id', 'name')->firstOrFail()->customer_code)->toBeNull();
});
