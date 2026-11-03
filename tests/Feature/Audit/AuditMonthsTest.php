<?php

use App\Modules\Admin\Livewire\Platform\Audit\Index;
use App\Modules\Admin\Support\AuditMonthPurgeRefused;
use App\Modules\Admin\Support\PurgeAuditMonth;
use App\Modules\Audit\AuditService;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Support\AuditMonths;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The audit log is one table per calendar month (UTC). The app (app_user, the default connection
 * here) reaches rows only through the parent table and may only add and read them; months are
 * created and dropped by the database functions the migration made, which refuse the running
 * month and the 12 before it whatever they are asked. The scheduled prune keeps the running month
 * and the 24 full months before it; the super-admin may drop an older month by hand, after
 * entering their password. A dropped month is always audited in the same transaction.
 *
 * The shared test database keeps the split table between tests; a test that takes it apart puts
 * it back in `finally`, and each test starts from the split table with its coming months ready.
 */

beforeEach(function (): void {
    amAsAdmin(fn () => amMigration()->up());
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate audit_logs restart identity cascade');
    $admin->statement('select public.audit_log_months_ensure()');
    foreach (range(-24, 0) as $offset) {
        $admin->statement('select public.audit_log_month_create(?::date)', [amMonth($offset).'-01']);
    }
});

function amMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2046_01_01_000000_partition_audit_logs_by_month.php');

    return $migration;
}

/**
 * Runs $run with the privileged connection as the default one, as the migrator does.
 *
 * @template T
 *
 * @param  Closure(): T  $run
 * @return T
 */
function amAsAdmin(Closure $run): mixed
{
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        return $run();
    } finally {
        DB::setDefaultConnection($original);
    }
}

/** The month $offset months from the running one (UTC, database clock), 'YYYY-MM'. */
function amMonth(int $offset): string
{
    return (string) DB::connection('pgsql_admin')->scalar(
        "select to_char(date_trunc('month', now() at time zone 'UTC') + make_interval(months => ?), 'YYYY-MM')",
        [$offset],
    );
}

function amTable(string $month): string
{
    return 'audit_logs_y'.substr($month, 0, 4).'m'.substr($month, 5, 2);
}

function amExists(string $month): bool
{
    return (bool) DB::connection('pgsql_admin')->scalar(
        "select exists (select 1 from pg_inherits where inhparent = 'public.audit_logs'::regclass and inhrelid = to_regclass(?))",
        ['public.'.amTable($month)],
    );
}

function amCreate(string $month): void
{
    DB::connection('pgsql_admin')->statement('select public.audit_log_month_create(?::date)', [$month.'-01']);
}

/** Drops a month through the function, with its audit row written first as AuditMonths does. */
function amDrop(string $month): mixed
{
    return DB::transaction(function () use ($month): mixed {
        $record = app(AuditService::class)->recordOrFail(PurgeAuditMonth::ACTION, ['meta' => ['month' => $month, 'rows' => 0]]);

        return DB::scalar('select public.audit_log_month_drop(?::date, ?)', [$month.'-01', $record]);
    });
}

/** A ulid no audit row carries. */
const AM_NO_ROW = '01AAAAAAAAAAAAAAAAAAAAAAAA';

function amDropAsAdmin(string $month): void
{
    DB::connection('pgsql_admin')->statement('drop table if exists public.'.amTable($month));
}

/** @param  array<string, mixed>  $attributes */
function amRow(string $createdAt, array $attributes = []): AuditLog
{
    return AuditLog::query()->create($attributes + ['action' => 'cms.menu.saved', 'meta' => [], 'created_at' => $createdAt]);
}

/** @return list<string> every month table of the audit log, oldest first */
function amMonths(): array
{
    return array_map(fn (array $m): string => $m['month'], app(AuditMonths::class)->months());
}

function amOperator(string $role, string $password = 'password'): User
{
    truncateRbac();
    seedTenancy();
    test()->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $user = User::factory()->create(['password' => $password]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

/** The message $run is refused with on $field, or null when it is not refused that way. */
function amRefusal(Closure $run, string $field): ?string
{
    try {
        $run();
    } catch (ValidationException $e) {
        return $e->errors()[$field][0] ?? null;
    }

    return null;
}

/** The SQLSTATE the database refuses $run with, or null when it is not refused. */
function amCode(Closure $run): ?string
{
    try {
        $run();
    } catch (QueryException $e) {
        return is_string($e->errorInfo[0] ?? null) ? $e->errorInfo[0] : null;
    }

    return null;
}

// --- conversion ---------------------------------------------------------------------------------

it('converts the plain table keeping every row, id, ulid and the column order, and continues the ids', function (): void {
    $old = amMonth(-30);   // older than the 24 months the conversion creates anyway
    $columns = fn (): array => array_map(
        fn (object $c): string => "{$c->column_name} {$c->data_type} {$c->is_nullable}",
        DB::connection('pgsql_admin')->select("select column_name, data_type, is_nullable from information_schema.columns where table_schema = 'public' and table_name = 'audit_logs' order by ordinal_position"),
    );

    try {
        amAsAdmin(fn () => amMigration()->down());
        $admin = DB::connection('pgsql_admin');
        foreach ([[7, $old.'-15 10:00:00+00'], [40, amMonth(-1).'-02 08:00:00+00'], [41, amMonth(0).'-01 00:00:00+00']] as [$id, $at]) {
            $admin->table('audit_logs')->insert(['id' => $id, 'ulid' => (string) Str::ulid(), 'action' => 'cms.menu.saved', 'meta' => '{"n": '.$id.'}', 'created_at' => $at]);
        }
        $plainColumns = $columns();
        $before = $admin->table('audit_logs')->orderBy('id')->get(['id', 'ulid', 'created_at', 'meta'])->all();
    } finally {
        amAsAdmin(fn () => amMigration()->up());
    }

    $after = DB::connection('pgsql_admin')->table('audit_logs')->orderBy('id')->get(['id', 'ulid', 'created_at', 'meta'])->all();
    $next = AuditLog::query()->create(['action' => 'cms.menu.saved', 'meta' => []]);

    expect(DB::connection('pgsql_admin')->scalar("select relkind::text from pg_class where oid = 'public.audit_logs'::regclass"))->toBe('p')
        ->and($after)->toEqual($before)
        ->and($columns())->toBe($plainColumns)
        ->and($next->id)->toBe(42)
        ->and(amExists($old))->toBeTrue()
        ->and(amMonths()[0])->toBe($old)
        ->and(end($after)->id)->toBe(41);
});

it('refuses to split a log holding a row dated far off, which would make a month for every month in between', function (string $at): void {
    try {
        amAsAdmin(fn () => amMigration()->down());
        $admin = DB::connection('pgsql_admin');
        $admin->table('audit_logs')->insert(['ulid' => (string) Str::ulid(), 'action' => 'cms.menu.saved', 'meta' => '{}', 'created_at' => $at]);

        expect(fn () => amAsAdmin(fn () => amMigration()->up()))->toThrow(RuntimeException::class, 'correct or remove them')
            ->and($admin->scalar("select relkind::text from pg_class where oid = 'public.audit_logs'::regclass"))->toBe('r');
    } finally {
        DB::connection('pgsql_admin')->table('audit_logs')->where('created_at', $at)->delete();
        amAsAdmin(fn () => amMigration()->up());
    }

    expect(DB::connection('pgsql_admin')->scalar("select relkind::text from pg_class where oid = 'public.audit_logs'::regclass"))->toBe('p');
})->with(['a clock reset to 1970' => '1970-01-01 00:00:00+00', 'a year typed wrong' => '2099-06-01 00:00:00+00']);

it('creates the 24 months before the running one and the 3 after it on an empty table, so older rows still fit', function (): void {
    try {
        amAsAdmin(fn () => amMigration()->down());
    } finally {
        amAsAdmin(fn () => amMigration()->up());
    }

    $expected = array_map(amMonth(...), range(-24, 3));
    amRow('2026-07-10 07:30:00+00');

    expect(amMonths())->toBe($expected)
        ->and(AuditLog::query()->count())->toBe(1);
});

it('restores the plain table with the same rows, ids and grants on down()', function (): void {
    $rows = [amRow(amMonth(-2).'-05 01:00:00+00'), amRow(amMonth(0).'-01 00:00:00+00')];
    $grants = fn (): array => array_map(
        fn (string $p): bool => (bool) DB::connection('pgsql_admin')->scalar("select has_table_privilege('app_user', 'public.audit_logs', ?)", [$p]),
        ['SELECT' => 'SELECT', 'INSERT' => 'INSERT', 'UPDATE' => 'UPDATE', 'DELETE' => 'DELETE'],
    );

    try {
        amAsAdmin(fn () => amMigration()->down());
        $admin = DB::connection('pgsql_admin');
        $kind = $admin->scalar("select relkind::text from pg_class where oid = 'public.audit_logs'::regclass");
        $back = $admin->table('audit_logs')->orderBy('id')->pluck('ulid', 'id')->all();
        $plainGrants = $grants();
        $unique = $admin->scalar("select pg_get_constraintdef(oid) from pg_constraint where conname = 'audit_logs_ulid_unique'");
        $functions = $admin->scalar("select count(*) from pg_proc where proname like 'audit\\_log\\_%'");
        $role = $admin->scalar("select count(*) from pg_roles where rolname = 'audit_owner'");
        $next = AuditLog::query()->create(['action' => 'cms.menu.saved', 'meta' => []]);
    } finally {
        amAsAdmin(fn () => amMigration()->up());
    }

    expect($kind)->toBe('r')
        ->and($back)->toBe([$rows[0]->id => $rows[0]->ulid, $rows[1]->id => $rows[1]->ulid])
        ->and($plainGrants)->toBe(['SELECT' => true, 'INSERT' => true, 'UPDATE' => false, 'DELETE' => false])
        ->and($unique)->toBe('UNIQUE (ulid)')
        ->and($functions)->toBe(0)
        ->and($role)->toBe(0)
        ->and($next->id)->toBe($rows[1]->id + 1);
});

it('leaves an already split table alone when the migration runs again', function (): void {
    amRow(amMonth(0).'-02 00:00:00+00');
    $oid = DB::connection('pgsql_admin')->scalar("select 'public.audit_logs'::regclass::oid");

    amAsAdmin(fn () => amMigration()->up());

    expect(DB::connection('pgsql_admin')->scalar("select 'public.audit_logs'::regclass::oid"))->toBe($oid)
        ->and(AuditLog::query()->count())->toBe(1);
});

// --- what the app may do -------------------------------------------------------------------------

it('lets the app add and read rows through the parent and nothing else', function (string $statement): void {
    amRow(amMonth(0).'-02 00:00:00+00');

    expect(DB::scalar('select current_user'))->toBe('app_user')
        ->and(AuditLog::query()->count())->toBe(1)
        ->and(fn () => DB::transaction(fn () => DB::statement($statement)))->toThrow(QueryException::class, 'permission denied')
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->count())->toBe(1);
})->with([
    'update through the parent' => ["update audit_logs set action = 'rewritten'"],
    'delete through the parent' => ['delete from audit_logs'],
]);

it('refuses the app any right on a month named directly, also on one created later', function (string $right): void {
    $later = amMonth(3);
    amDropAsAdmin($later);
    expect(Cast::int(DB::scalar('select public.audit_log_months_ensure()')))->toBe(1);
    amRow(amMonth(0).'-02 00:00:00+00');

    foreach ([amTable(amMonth(0)), amTable($later)] as $table) {
        $statement = match ($right) {
            'select' => "select count(*) from public.{$table}",
            'insert' => "insert into public.{$table} (ulid, action, created_at) values ('x', 'x', now())",
            'update' => "update public.{$table} set action = 'rewritten'",
            'delete' => "delete from public.{$table}",
        };

        expect(fn () => DB::transaction(fn () => DB::statement($statement)))->toThrow(QueryException::class, 'permission denied');
    }

    $granted = DB::connection('pgsql_admin')->scalar(<<<'SQL'
        select count(*) from pg_inherits i
        where i.inhparent = 'public.audit_logs'::regclass
          and (has_table_privilege('app_user', i.inhrelid, 'SELECT') or has_table_privilege('app_user', i.inhrelid, 'INSERT')
            or has_table_privilege('app_user', i.inhrelid, 'UPDATE') or has_table_privilege('app_user', i.inhrelid, 'DELETE'))
        SQL);

    expect($granted)->toBe(0)
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->count())->toBe(1);
})->with(['select', 'insert', 'update', 'delete']);

it('lets the app run only the month functions meant for it', function (): void {
    expect(fn () => DB::transaction(fn () => DB::statement('select public.audit_log_month_create(?::date)', [amMonth(-30).'-01'])))
        ->toThrow(QueryException::class, 'permission denied');
    expect(amExists(amMonth(-30)))->toBeFalse();
});

// --- months and their edges ----------------------------------------------------------------------

it('puts the last second of a month and the first of the next into their own months', function (): void {
    $month = amMonth(-1);
    $next = amMonth(0);
    $last = date('Y-m-t', (int) strtotime($month.'-01'));
    $placed = fn (AuditLog $row): string => (string) DB::connection('pgsql_admin')->scalar('select tableoid::regclass::text from audit_logs where id = ?', [$row->id]);

    $lastSecond = amRow("{$last} 23:59:59+00");
    $firstSecond = amRow("{$next}-01 00:00:00+00");
    // The app writes whole seconds, so the last instant of a month stays in it. created_at keeps
    // whole seconds too: a fraction written straight in SQL is rounded, and the row goes where
    // its stored time says.
    $lastInstant = amRow("{$last} 23:59:59.999999+00");
    $rounded = (int) DB::scalar("insert into audit_logs (ulid, action, created_at) values (?, 'cms.menu.saved', ?) returning id", [(string) Str::ulid(), "{$last} 23:59:59.999+00"]);
    $stored = DB::scalar("select to_char(created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') from audit_logs where id = ?", [$rounded]);
    $roundedPlace = DB::connection('pgsql_admin')->scalar('select tableoid::regclass::text from audit_logs where id = ?', [$rounded]);

    expect($placed($lastSecond))->toBe(amTable($month))
        ->and($placed($firstSecond))->toBe(amTable($next))
        ->and($placed($lastInstant))->toBe(amTable($month))
        ->and($stored)->toBe("{$next}-01 00:00:00")
        ->and($roundedPlace)->toBe(amTable($next));
});

it('ensures the coming months idempotently', function (): void {
    amDropAsAdmin(amMonth(2));
    amDropAsAdmin(amMonth(3));

    $first = Cast::int(DB::scalar('select public.audit_log_months_ensure()'));
    $months = amMonths();
    $second = Cast::int(DB::scalar('select public.audit_log_months_ensure()'));

    expect($first)->toBe(2)
        ->and($second)->toBe(0)
        ->and(amMonths())->toBe($months)
        ->and(array_slice($months, -4))->toBe(array_map(amMonth(...), range(0, 3)));
});

it('ensures the same UTC months whatever time zone the session uses and whatever temporary table it made', function (): void {
    $later = amMonth(3);
    amDropAsAdmin($later);

    DB::transaction(function () use ($later): void {
        DB::statement("SET LOCAL TimeZone = 'Etc/GMT-14'");   // UTC+14
        DB::statement('create temporary table '.amTable($later).' (id int) on commit drop');
        DB::statement('select public.audit_log_months_ensure()');
        expect(DB::scalar('select count(*) from pg_temp.'.amTable($later)))->toBe(0);
    });

    $bound = DB::connection('pgsql_admin')->scalar('select pg_get_expr(relpartbound, oid) from pg_class where oid = to_regclass(?)', ['public.'.amTable($later)]);
    $after = date('Y-m', (int) strtotime($later.'-01 +1 month'));

    expect(amExists($later))->toBeTrue()
        ->and($bound)->toBe("FOR VALUES FROM ('{$later}-01 00:00:00+00') TO ('{$after}-01 00:00:00+00')");
});

it('refuses to drop the running month or any of the 12 before it', function (int $back): void {
    $month = amMonth(-$back);

    expect(fn () => amDrop($month))
        ->toThrow(QueryException::class, 'is kept')
        ->and(amExists($month))->toBeTrue();
})->with(range(0, 12));

it('refuses a day that does not start a month, and infinity', function (string $day): void {
    expect(fn () => DB::scalar('select public.audit_log_month_drop(?::date, ?)', [$day, AM_NO_ROW]))
        ->toThrow(QueryException::class, 'first day of a month');
})->with([
    'the 15th' => fn (): string => amMonth(-20).'-15',
    'infinity' => 'infinity',
    'minus infinity' => '-infinity',
]);

it('does nothing for a NULL month', function (): void {
    $before = amMonths();

    expect(DB::scalar('select public.audit_log_month_drop(null::date, ?)', [AM_NO_ROW]))->toBeNull()
        ->and(DB::scalar("select public.audit_log_month_drop('1990-01-01'::date, null)"))->toBeNull()
        ->and(amMonths())->toBe($before);
});

it('drops an old month and returns its row count', function (): void {
    $month = amMonth(-13);
    amRow($month.'-03 00:00:00+00');
    amRow($month.'-20 00:00:00+00');
    amRow(amMonth(0).'-01 00:00:00+00');

    try {
        $dropped = amDrop($month);
        $gone = ! amExists($month);
    } finally {
        amCreate($month);
    }

    expect($dropped)->toBe(2)
        ->and($gone)->toBeTrue()
        ->and(AuditLog::query()->where('action', '<>', PurgeAuditMonth::ACTION)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', PurgeAuditMonth::ACTION)->pluck('meta')->all())
        ->toEqual([['month' => $month, 'rows' => 2]]);
});

it('refuses a month that does not exist, so a second call cannot rewrite the count its row recorded', function (): void {
    $month = amMonth(-13);
    amRow($month.'-03 00:00:00+00');

    try {
        $record = DB::transaction(function () use ($month): string {
            $record = app(AuditService::class)->recordOrFail(PurgeAuditMonth::ACTION, ['meta' => ['month' => $month, 'rows' => 0]]);
            DB::scalar('select public.audit_log_month_drop(?::date, ?)', [$month.'-01', $record]);

            return $record;
        });
        $again = amCode(fn () => DB::transaction(fn () => DB::scalar('select public.audit_log_month_drop(?::date, ?)', [$month.'-01', $record])));
    } finally {
        amCreate($month);
    }

    expect($again)->toBe('AUGON')
        ->and(amCode(fn () => amDrop('1990-01')))->toBe('AUGON')
        ->and(AuditLog::query()->where('action', PurgeAuditMonth::ACTION)->pluck('meta')->all())
        ->toEqual([['month' => $month, 'rows' => 1]]);
});

it('refuses to drop a month without its audit row, whoever calls', function (string $who): void {
    $month = amMonth(-13);
    amRow($month.'-03 00:00:00+00');
    $connection = $who === 'app' ? DB::connection() : DB::connection('pgsql_admin');
    // a row for another month, or one written long ago, does not count either
    $other = amRow(amMonth(0).'-01 00:00:00+00', ['action' => PurgeAuditMonth::ACTION, 'meta' => ['month' => amMonth(-14)]]);
    $old = amRow(amMonth(0).'-01 00:00:00+00', ['action' => PurgeAuditMonth::ACTION, 'meta' => ['month' => $month], 'created_at' => now()->subHour()]);

    foreach ([AM_NO_ROW, $other->ulid, $old->ulid] as $record) {
        expect(fn () => $connection->scalar('select public.audit_log_month_drop(?::date, ?)', [$month.'-01', $record]))
            ->toThrow(QueryException::class, 'only after its audit row is written');
    }

    expect(amExists($month))->toBeTrue();
})->with(['app', 'migration role']);

it('refuses to drop a table named like a month that is not part of the log', function (): void {
    DB::connection('pgsql_admin')->statement('create table public.audit_logs_y1999m01 (id int)');

    try {
        expect(fn () => amDrop('1999-01'))
            ->toThrow(QueryException::class, 'is not a month of the audit log');
        expect(DB::connection('pgsql_admin')->scalar("select to_regclass('public.audit_logs_y1999m01') is not null"))->toBeTrue();
    } finally {
        DB::connection('pgsql_admin')->statement('drop table if exists public.audit_logs_y1999m01');
    }
});

it('counts the rows of one month exactly, for the confirmation', function (): void {
    $month = amMonth(-14);
    amRow($month.'-01 00:00:00+00');
    amRow($month.'-28 23:59:59+00');
    amRow(amMonth(-13).'-01 00:00:00+00');

    expect(app(AuditMonths::class)->rows($month))->toBe(2);
});

// --- manual cleanup ------------------------------------------------------------------------------

it('lets the platform super-admin delete an old month after entering the password, and audits it', function (): void {
    $actor = amOperator('super_admin');
    $month = amMonth(-13);
    amRow($month.'-03 00:00:00+00');
    amRow($month.'-04 00:00:00+00');

    try {
        $rows = app(PurgeAuditMonth::class)->handle($actor, $month, 'password');
        $gone = ! amExists($month);
    } finally {
        amCreate($month);
    }

    $row = AuditLog::query()->where('action', PurgeAuditMonth::ACTION)->sole();

    expect($rows)->toBe(2)
        ->and($gone)->toBeTrue()
        ->and($row->getAttribute('actor_id'))->toBe($actor->id)
        ->and($row->meta)->toMatchArray(['month' => $month, 'rows' => 2]);
});

it('refuses manual cleanup to anyone but the platform super-admin', function (): void {
    $actor = amOperator('support');
    $month = amMonth(-13);
    amRow($month.'-03 00:00:00+00');

    expect(fn () => app(PurgeAuditMonth::class)->handle($actor, $month, 'password'))->toThrow(AuthorizationException::class)
        ->and(amExists($month))->toBeTrue()
        ->and(AuditLog::query()->count())->toBe(1);
});

it('refuses a wrong password, and stops trying after five within a minute', function (): void {
    $actor = amOperator('super_admin');
    $month = amMonth(-13);
    $purge = fn (string $password): Closure => fn () => app(PurgeAuditMonth::class)->handle($actor, $month, $password);

    foreach (range(1, PurgeAuditMonth::ATTEMPTS) as $attempt) {
        expect(amRefusal($purge('not-it'), 'password'))->toBe(__('admin.audit.purge.wrong_password'));
    }

    expect(amRefusal($purge('password'), 'password'))->toStartWith(explode(':seconds', __('admin.audit.purge.throttled'))[0])
        ->and(amExists($month))->toBeTrue();
});

it('counts wrong passwords per person, so one super-admin locked out does not lock out another', function (): void {
    $first = amOperator('super_admin');
    $second = User::factory()->create(['password' => 'password']);
    app(PlatformAccess::class)->onPlatform(fn () => $second->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();
    $month = amMonth(-13);

    foreach (range(1, PurgeAuditMonth::ATTEMPTS + 1) as $attempt) {
        amRefusal(fn () => app(PurgeAuditMonth::class)->handle($first, $month, 'not-it'), 'password');
    }

    expect(amRefusal(fn () => app(PurgeAuditMonth::class)->handle($second, $month, 'not-it'), 'password'))
        ->toBe(__('admin.audit.purge.wrong_password'));
});

it('tells the super-admin to try again later when the log is busy, and keeps the month', function (): void {
    $actor = amOperator('super_admin');
    $month = amMonth(-13);
    amRow($month.'-03 00:00:00+00');
    $admin = DB::connection('pgsql_admin');
    $admin->beginTransaction();
    $admin->statement('lock table public.audit_logs in access share mode');

    try {
        $message = amRefusal(fn () => app(PurgeAuditMonth::class)->handle($actor, $month, 'password'), 'month');
    } finally {
        $admin->rollBack();
    }

    expect($message)->toBe(__('admin.audit.purge.busy'))
        ->and(amExists($month))->toBeTrue()
        ->and(AuditLog::query()->where('action', PurgeAuditMonth::ACTION)->count())->toBe(0);
});

it('refuses by hand a month the log does not have', function (): void {
    $actor = amOperator('super_admin');

    expect(amRefusal(fn () => app(PurgeAuditMonth::class)->handle($actor, '1990-01', 'password'), 'month'))
        ->toBe(__('admin.audit.purge.missing'))
        ->and(fn () => app(PurgeAuditMonth::class)->handle($actor, '1990-01', 'password'))
        ->toThrow(fn (AuditMonthPurgeRefused $e) => expect($e->reason)->toBe('missing'))
        ->and(AuditLog::query()->where('action', PurgeAuditMonth::ACTION)->count())->toBe(0);
});

it('refuses manual cleanup to an account without a password', function (): void {
    $actor = amOperator('super_admin');
    DB::connection('pgsql_admin')->table('users')->where('id', $actor->id)->update(['password' => '']);
    $month = amMonth(-13);
    $actor = $actor->fresh() ?? $actor;

    expect(amRefusal(fn () => app(PurgeAuditMonth::class)->handle($actor, $month, 'anything'), 'password'))->toBe(__('admin.audit.purge.no_password'))
        ->and(amExists($month))->toBeTrue();
});

it('refuses by hand the running month and the 12 before it, with a plain message', function (): void {
    $actor = amOperator('super_admin');
    $month = amMonth(-12);

    expect(amRefusal(fn () => app(PurgeAuditMonth::class)->handle($actor, $month, 'password'), 'month'))
        ->toBe(__('admin.audit.purge.too_recent', ['months' => 12]))
        ->and(amExists($month))->toBeTrue();
});

it('refuses a month that is not written as one', function (string $month): void {
    $actor = amOperator('super_admin');

    expect(amRefusal(fn () => app(PurgeAuditMonth::class)->handle($actor, $month, 'password'), 'month'))->not->toBeNull();
})->with(['2024-13', '2024-1', '2024-01-01', "2024-01'; drop table audit_logs; --"]);

it('keeps the month when its audit row cannot be written', function (): void {
    $actor = amOperator('super_admin');
    $month = amMonth(-13);
    amRow($month.'-03 00:00:00+00');
    $admin = DB::connection('pgsql_admin');
    $admin->unprepared(<<<'SQL'
        create or replace function test_audit_month_row_fails() returns trigger language plpgsql as $fn$
        begin
            raise exception 'audit row refused for the test';
        end
        $fn$;
        drop trigger if exists test_audit_month_row_fails on audit_logs;
        create trigger test_audit_month_row_fails before insert on audit_logs
            for each row when (new.action = 'audit.month_deleted')
            execute function test_audit_month_row_fails();
        SQL);

    try {
        expect(fn () => app(PurgeAuditMonth::class)->handle($actor, $month, 'password'))
            ->toThrow(QueryException::class, 'audit row refused for the test');
    } finally {
        $admin->unprepared('drop trigger if exists test_audit_month_row_fails on audit_logs; drop function if exists test_audit_month_row_fails();');
        amCreate($month);
    }

    expect(amExists($month))->toBeTrue()
        ->and(AuditLog::query()->where('created_at', '<', amMonth(-12).'-01')->count())->toBe(1);
});

// --- scheduled commands --------------------------------------------------------------------------

it('prunes only the months older than 24 full months, audits it, and does nothing on a rerun', function (): void {
    foreach (app(AuditMonths::class)->expired() as $leftOver) {
        amDrop($leftOver);
    }
    [$kept, $old, $older] = [amMonth(-24), amMonth(-25), amMonth(-26)];
    amCreate($old);
    amCreate($older);
    amRow($old.'-10 00:00:00+00');
    amRow($kept.'-10 00:00:00+00');

    $this->artisan('audit:prune')->expectsOutputToContain("Removed 2 audit log months ({$older}, {$old}) with 1 rows.")->assertSuccessful();

    expect(amExists($old))->toBeFalse()
        ->and(amExists($older))->toBeFalse()
        ->and(amExists($kept))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'audit.months_pruned')->orderBy('id')->pluck('meta')->all())
        ->toEqual([['month' => $older, 'rows' => 0], ['month' => $old, 'rows' => 1]]);

    $this->artisan('audit:prune')->expectsOutputToContain('No audit log month is older than 24 full months.')->assertSuccessful();

    expect(AuditLog::query()->where('action', 'audit.months_pruned')->count())->toBe(2)
        ->and(amExists($kept))->toBeTrue();
});

it('prunes the other months when one is busy, leaves that one for the next run and says so', function (): void {
    foreach (app(AuditMonths::class)->expired() as $leftOver) {
        amDrop($leftOver);
    }
    [$old, $older] = [amMonth(-25), amMonth(-26)];
    amCreate($old);
    amCreate($older);
    $admin = DB::connection('pgsql_admin');
    $admin->beginTransaction();
    $admin->statement('lock table public.'.amTable($older).' in access share mode');

    try {
        $this->artisan('audit:prune')
            ->expectsOutputToContain("Audit log month {$older} not removed (busy)")
            ->expectsOutputToContain("Removed 1 audit log months ({$old}) with 0 rows.")
            ->assertFailed();
    } finally {
        $admin->rollBack();
    }

    expect(amExists($older))->toBeTrue()
        ->and(amExists($old))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'audit.months_pruned')->pluck('meta')->all())
        ->toEqual([['month' => $old, 'rows' => 0]]);

    $this->artisan('audit:prune')->expectsOutputToContain("Removed 1 audit log months ({$older}) with 0 rows.")->assertSuccessful();
});

it('creates the coming months and warns when fewer than two are ready because it could not lock the log', function (): void {
    amDropAsAdmin(amMonth(2));
    amDropAsAdmin(amMonth(3));
    $admin = DB::connection('pgsql_admin');

    $admin->beginTransaction();
    try {
        $admin->statement('lock table audit_logs in share update exclusive mode');
        $this->artisan('audit:months')->expectsOutputToContain('Only 1 future months of the audit log exist')->assertFailed();
    } finally {
        $admin->rollBack();
    }

    expect(amExists(amMonth(2)))->toBeFalse();

    $this->artisan('audit:months')->expectsOutputToContain('Created 2 audit log months; 3 months are ready after the running one.')->assertSuccessful();

    expect(amExists(amMonth(2)))->toBeTrue()
        ->and(amExists(amMonth(3)))->toBeTrue();
});

it('schedules the months daily and the prune on the 1st of each month, in UTC', function (string $command, string $expression): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $e): bool => str_contains((string) $e->command, $command));

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe($expression)
        ->and($event?->timezone)->toBe('UTC')
        ->and($event?->withoutOverlapping)->toBeTrue();
})->with([
    'months' => ['audit:months', '0 3 * * *'],
    'prune' => ['audit:prune', '30 3 1 * *'],
]);

// --- reading the log -----------------------------------------------------------------------------

it('reads an older page from the months up to the anchor only', function (): void {
    foreach (range(1, 12) as $n) {
        amRow(amMonth(-1).sprintf('-%02d 10:00:00+00', $n), ['actor_label' => 'x@example.test']);
    }
    amRow(amMonth(0).'-01 10:00:00+00');
    $operator = amOperator('support');
    $anchor = AuditLog::query()->orderBy('created_at')->orderBy('id')->skip(5)->first(['ulid']);
    $page = null;
    DB::listen(function (QueryExecuted $query) use (&$page): void {
        if (str_contains($query->sql, 'from "audit_logs"') && str_contains($query->sql, '(created_at, id) <')) {
            $page = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        }
    });

    Livewire::actingAs($operator)->test(Index::class)->set('after', (string) $anchor?->ulid)->assertOk();

    expect($page)->not->toBeNull();
    $plan = json_encode(DB::select('EXPLAIN (FORMAT JSON) '.$page['sql'], $page['bindings']));

    expect($page['sql'])->toContain('"created_at" <= ?')
        ->and($plan)->toContain(amTable(amMonth(-1)))
        ->and($plan)->not->toContain(amTable(amMonth(0)))
        ->and($plan)->not->toContain(amTable(amMonth(1)));
});
