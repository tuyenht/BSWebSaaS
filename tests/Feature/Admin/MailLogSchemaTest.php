<?php

use App\Modules\Admin\Support\Mail\MailMonths;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The mail log is one table per calendar month (UTC), kept 90 days; the day counts sit in
 * mail_daily. The app (app_user, the default connection here) may add and read rows through the
 * parent tables only and add to its own day counts; months are created and dropped, and old day
 * counts removed, only by the database functions the migration made, which refuse the running month
 * and the three before it, and any day of the last 90, whatever they are asked.
 */

beforeEach(function (): void {
    seedTenancy();
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate mail_logs restart identity cascade');
    $admin->statement('select public.mail_log_months_ensure()');
});

afterEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate mail_logs restart identity cascade');
});

/** 'YYYY-MM' of the UTC month $offset months from the running one. */
function mlsMonth(int $offset): string
{
    return now('UTC')->startOfMonth()->addMonthsNoOverflow($offset)->format('Y-m');
}

function mlsTable(string $month): string
{
    return 'mail_logs_y'.str_replace('-', 'm', $month);
}

/** The SQLSTATE $run fails with, or null when it does not fail. */
function mlsFailure(Closure $run): ?string
{
    try {
        $run();
    } catch (QueryException $e) {
        return is_string($e->errorInfo[0] ?? null) ? $e->errorInfo[0] : 'unknown';
    }

    return null;
}

it('has the running month and the three after it ready', function (): void {
    $months = app(MailMonths::class)->months();

    foreach (range(0, 3) as $offset) {
        expect($months)->toContain(mlsMonth($offset));
    }
    expect(app(MailMonths::class)->monthsAhead())->toBeGreaterThanOrEqual(3);
});

it('forces row security on the log, each of its months and the day counts', function (): void {
    $tables = ['mail_logs', 'mail_daily', ...array_map(fn (string $m): string => mlsTable($m), app(MailMonths::class)->months())];

    foreach ($tables as $table) {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select c.relrowsecurity as rls, c.relforcerowsecurity as force,
                (select count(*) from pg_policies p where p.schemaname = \'public\' and p.tablename = c.relname) as policies
            from pg_class c where c.oid = ?::regclass',
            ['public.'.$table],
        );
        expect($row->rls)->toBeTrue("{$table} rls")
            ->and($row->force)->toBeTrue("{$table} force")
            ->and((int) $row->policies)->toBeGreaterThan(0);
    }
});

it('does not let the app change the log, its months or another website’s day counts', function (): void {
    $month = mlsTable(mlsMonth(0));
    DB::connection('pgsql_admin')->table('mail_daily')->insert(['site_id' => 2, 'day' => now('UTC')->toDateString(), 'via' => 'platform', 'sent' => 7]);

    expect(mlsFailure(fn () => DB::statement("drop table public.{$month}")))->toBe('42501')
        ->and(mlsFailure(fn () => DB::statement("alter table public.mail_logs detach partition public.{$month}")))->toBe('42501')
        ->and(mlsFailure(fn () => DB::statement("insert into public.{$month} (site_id, kind, via, recipient_count, status) values (1, 'lead', 'platform', 1, 'sent')")))->toBe('42501')
        ->and(mlsFailure(fn () => DB::statement('delete from mail_logs')))->toBe('42501')
        ->and(mlsFailure(fn () => DB::statement("update mail_logs set status = 'sent'")))->toBe('42501')
        ->and(mlsFailure(fn () => DB::statement('truncate mail_logs')))->toBe('42501')
        ->and(mlsFailure(fn () => DB::statement('delete from mail_daily')))->toBe('42501')
        ->and(mlsFailure(fn () => DB::statement('truncate mail_daily')))->toBe('42501')
        ->and(mlsFailure(fn () => DB::statement("select public.mail_log_month_create('2030-01-01'::date)")))->toBe('42501');

    // Site 1 cannot see, let alone change, site 2's day.
    $changed = app(Tenancy::class)->enter(1, fn (): int => DB::update('update mail_daily set sent = 0 where site_id = 2'));
    expect($changed)->toBe(0)
        ->and((int) DB::connection('pgsql_admin')->table('mail_daily')->where('site_id', 2)->value('sent'))->toBe(7);
});

it('refuses to drop a recent month or recent day counts, whatever it is asked', function (): void {
    foreach ([0, -1, -3, 1] as $offset) {
        expect(mlsFailure(fn () => DB::transaction(fn () => DB::scalar('select public.mail_log_month_drop(?::date)', [mlsMonth($offset).'-01']))))->toBe('MLKPT');
    }
    expect(mlsFailure(fn () => DB::scalar('select public.mail_daily_prune(?::date)', [now('UTC')->subDays(89)->toDateString()])))->toBe('MLKPT')
        ->and(mlsFailure(fn () => DB::scalar("select public.mail_log_month_drop('2020-01-15'::date)")))->toBe('22023')
        ->and((bool) DB::scalar("select public.mail_log_month_drop('2020-01-01'::date)"))->toBeFalse();
});

it('drops the months and day counts older than 90 days, and nothing newer', function (): void {
    $admin = DB::connection('pgsql_admin');
    $old = now('UTC')->startOfMonth()->subMonthsNoOverflow(5);
    $admin->statement('select public.mail_log_month_create(?::date)', [$old->toDateString()]);
    $admin->statement('select public.mail_log_month_create(?::date)', [mlsMonth(-3).'-01']); // not all of it past 90 days
    $admin->table('mail_logs')->insert(['site_id' => 1, 'kind' => 'lead', 'via' => 'platform', 'recipient_count' => 1, 'status' => 'sent', 'created_at' => $old->copy()->addDays(3)]);
    $admin->table('mail_logs')->insert(['site_id' => 1, 'kind' => 'lead', 'via' => 'platform', 'recipient_count' => 1, 'status' => 'sent']);
    $admin->table('mail_daily')->insert([
        ['site_id' => 1, 'day' => now('UTC')->subDays(91)->toDateString(), 'via' => 'platform', 'sent' => 3],
        ['site_id' => 1, 'day' => now('UTC')->subDays(30)->toDateString(), 'via' => 'platform', 'sent' => 4],
        ['site_id' => 2, 'day' => now('UTC')->toDateString(), 'via' => 'own', 'sent' => 5],
    ]);

    $this->artisan('mail:prune')->expectsOutputToContain($old->format('Y-m'))->assertSuccessful();

    expect(app(MailMonths::class)->months())->not->toContain($old->format('Y-m'))
        ->and(app(MailMonths::class)->months())->toContain(mlsMonth(-3))
        ->and($admin->table('mail_logs')->count())->toBe(1)
        ->and($admin->table('mail_daily')->orderBy('sent')->pluck('sent')->all())->toBe([4, 5]);

    // Again: nothing more to remove.
    $this->artisan('mail:prune')->expectsOutputToContain('Removed 0 mail log months')->assertSuccessful();
});

it('creates the missing coming months and counts how many are ready', function (): void {
    $this->artisan('mail:months')->expectsOutputToContain('months are ready after the running one')->assertSuccessful();

    $admin = DB::connection('pgsql_admin');
    try {
        $admin->statement('alter table public.mail_logs detach partition public.'.mlsTable(mlsMonth(1)));
        $admin->statement('drop table public.'.mlsTable(mlsMonth(1)));

        expect(app(MailMonths::class)->monthsAhead())->toBe(0);
    } finally {
        $this->artisan('mail:months')->assertSuccessful();
    }

    expect(app(MailMonths::class)->months())->toContain(mlsMonth(1));
});

it('schedules the months and the prune daily, in UTC', function (string $command, string $expression): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $e): bool => str_contains((string) $e->command, $command));

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe($expression)
        ->and($event?->timezone)->toBe('UTC')
        ->and($event?->withoutOverlapping)->toBeTrue();
})->with([
    'months' => ['mail:months', '5 3 * * *'],
    'prune' => ['mail:prune', '35 3 * * *'],
]);
