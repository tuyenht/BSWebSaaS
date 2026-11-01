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

/**
 * Runs $scenario against a scratch copy of tenants (cn_scratch, numbered from its own sequence
 * cn_scratch_seq), never the live table: dropping and re-adding tenants.customer_no would move the
 * column to the end of the table, so dev would drift from prod and pooled connections holding a
 * prepared statement on tenants would fail. Everything runs in one pgsql_admin transaction that
 * is rolled back, so the scratch table and its sequence never outlive the test.
 *
 * $scenario receives: number() runs the migration's numbering on the scratch table; row(id,
 * created_at, deleted_at = null, customer_no = null) adds a customer; numbers() lists customer_no
 * by id; next() draws the number the next new customer would get.
 */
function cnScratch(Closure $scenario): void
{
    /** @var Migration $migration */
    $migration = require database_path(CUSTOMER_NO_MIGRATION);
    $admin = DB::connection('pgsql_admin');

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    $admin->beginTransaction();
    try {
        $admin->statement('CREATE TEMP TABLE cn_scratch (LIKE tenants INCLUDING DEFAULTS)');
        $admin->statement('CREATE TEMP SEQUENCE cn_scratch_seq AS bigint');
        $admin->statement('ALTER TABLE cn_scratch ALTER COLUMN id DROP DEFAULT');
        $admin->statement('ALTER TABLE cn_scratch ALTER COLUMN customer_no DROP NOT NULL');
        $admin->statement("ALTER TABLE cn_scratch ALTER COLUMN customer_no SET DEFAULT nextval('cn_scratch_seq')");

        $scenario(
            fn () => $migration->numberUnnumbered('cn_scratch', 'cn_scratch_seq'),
            fn (int $id, string $createdAt, ?string $deletedAt = null, ?int $customerNo = null) => $admin->table('cn_scratch')->insert([
                'id' => $id, 'ulid' => (string) Str::ulid(), 'name' => "Customer {$id}", 'settings' => '{}',
                'created_at' => $createdAt, 'updated_at' => $createdAt, 'deleted_at' => $deletedAt, 'customer_no' => $customerNo,
            ]),
            fn (): array => $admin->table('cn_scratch')->orderBy('id')->pluck('customer_no', 'id')->all(),
            fn (): int => (int) $admin->scalar("SELECT nextval('cn_scratch_seq')"),
        );
    } finally {
        $admin->rollBack();
        DB::setDefaultConnection($original);
    }
}

it('numbers the customers that already exist oldest first, ties by id, deleted ones too', function (): void {
    cnScratch(function (Closure $number, Closure $row, Closure $numbers, Closure $next): void {
        $row(10, '2026-03-01 08:00:00+00');                               // March
        $row(12, '2026-01-15 08:00:00+00');                               // January, the higher id
        $row(11, '2026-01-15 08:00:00+00');                               // January, the lower id
        $row(13, '2026-02-01 08:00:00+00', '2026-02-02 08:00:00+00');     // February, soft-deleted

        $number();

        expect($numbers())->toBe([10 => 4, 11 => 1, 12 => 2, 13 => 3])
            ->and($next())->toBe(5);
    });
});

it('starts at 1 when there were no customers', function (): void {
    cnScratch(function (Closure $number, Closure $row, Closure $numbers, Closure $next): void {
        $number();

        expect($next())->toBe(1);
    });
});

it('numbers only the customers without a number when it runs again', function (): void {
    cnScratch(function (Closure $number, Closure $row, Closure $numbers, Closure $next): void {
        $row(1, '2026-01-01 08:00:00+00');
        $row(2, '2026-01-02 08:00:00+00');
        $number();

        // Two customers without a number yet (as after a run interrupted before NOT NULL), the
        // second one older than everyone: they follow the highest number, oldest first, and
        // nobody numbered before is renumbered.
        $row(3, '2026-01-05 08:00:00+00');
        $row(4, '2025-06-01 08:00:00+00');
        $number();

        expect($numbers())->toBe([1 => 1, 2 => 2, 3 => 4, 4 => 3])
            ->and($next())->toBe(5);
    });
});

it('gives the next customer the number after the last one, even from code that does not know the column', function (): void {
    cnTenant('Old', '2026-01-01 08:00:00+00');
    cnTenant('Older', '2025-01-01 08:00:00+00');

    // app_user, the role the application runs as, draws from the sequence too.
    DB::table('tenants')->insert(['ulid' => (string) Str::ulid(), 'name' => 'New', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $created = Tenant::query()->create(['ulid' => (string) Str::ulid(), 'name' => 'Newer', 'status' => 'active']);

    expect(DB::table('tenants')->where('name', 'New')->value('customer_no'))->toBe(3)
        ->and(Tenant::query()->findOrFail($created->id)->customer_no)->toBe(4);
});

it('runs the whole migration again without renumbering anyone', function (): void {
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
