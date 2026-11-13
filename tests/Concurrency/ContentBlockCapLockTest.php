<?php

use App\Modules\Cms\Actions\ContentBlocks\CreateContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\DuplicateContentBlock;
use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * A website keeps at most 200 blocks, and creating or copying a block decides "is there room?" and
 * "which reference code is free?" under one per-site advisory lock taken inside the write's
 * transaction, before it counts. Two creates racing for the 200th place therefore run one after
 * the other: the second waits for the first to finish, then counts 200 and is refused.
 *
 * PHP runs one statement at a time, so the race is staged on two real connections: the first
 * create runs on pgsql_admin and stays uncommitted while the second runs on the app connection
 * with a short lock_timeout. If the second did not take the lock first it would count 199 (it
 * cannot see the first's row) and get in, leaving 201; with the lock it waits and times out, and
 * once the first commits it is refused with library_full.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    $this->editor = blockLibraryUser(1, 'editor');

    $rows = [];
    for ($i = 1; $i <= 199; $i++) {
        $rows[] = ['ulid' => sprintf('01JBLOCKRACE%014d', $i), 'site_id' => 1, 'key' => "khoi-co-san-{$i}", 'name' => "Khối {$i}"];
    }
    DB::connection('pgsql_admin')->table('content_blocks')->insert($rows);
});

/** $user as read through pgsql_admin, so its own queries (roles, rights) run on that connection. */
function onAdminConnection(User $user): User
{
    return User::on('pgsql_admin')->findOrFail($user->id);
}

/** How many blocks site 1 keeps, committed (read past RLS). */
function raceBlockCount(): int
{
    return DB::connection('pgsql_admin')->table('content_blocks')->where('site_id', 1)->count();
}

/**
 * Run $write on the app connection, in site 1, giving up on a lock after a short wait. Returns the
 * refusal reason, the SQLSTATE of a database error (55P03 = gave up waiting for a lock), or null
 * when the write went through.
 */
function raceOnAppConnection(Closure $write): ?string
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql');

    try {
        return app(Tenancy::class)->enter(1, function () use ($write): ?string {
            DB::statement("set local lock_timeout = '300ms'");
            try {
                $write();
            } catch (ContentBlockChangeRejectedException $e) {
                return $e->reason;
            } catch (QueryException $e) {
                return (string) $e->getCode();
            }

            return null;
        });
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/**
 * Hold site 1's block-library lock on pgsql_admin by running $first there (uncommitted), run
 * $second on the app connection meanwhile, then commit the first. Returns what the second got.
 */
function raceWhileFirstOpen(Closure $first, Closure $second): ?string
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        return app(Tenancy::class)->enter(1, function () use ($first, $second): ?string {
            $first();

            return raceOnAppConnection($second);
        });
    } finally {
        DB::setDefaultConnection($previous);
    }
}

it('lets exactly one of two racing creates take the 200th place', function (): void {
    /** @var User $editor */
    $editor = $this->editor;
    $create = fn (string $name): ContentBlock => app(CreateContentBlock::class)->handle($editor, $name);

    // The second create waits for the first one's lock: it neither counts 199 nor gets in.
    $first = onAdminConnection($editor);
    $whileOpen = raceWhileFirstOpen(
        fn () => app(CreateContentBlock::class)->handle($first, 'Khối thứ nhất'),
        fn () => $create('Khối thứ hai'),
    );
    expect($whileOpen)->toBe('55P03')
        ->and(raceBlockCount())->toBe(200);

    // Run again once the first has committed: it now counts 200 and is refused.
    /** @var list<array{sql: string, level: int}> $queries */
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if ($query->connectionName === 'pgsql') {
            $queries[] = ['sql' => strtolower($query->sql), 'level' => $query->connection->transactionLevel()];
        }
    });
    $retry = raceOnAppConnection(fn () => $create('Khối thứ hai'));

    $lock = collect($queries)->search(fn (array $q): bool => str_contains($q['sql'], 'pg_advisory_xact_lock'));
    $count = collect($queries)->search(fn (array $q): bool => str_contains($q['sql'], 'count(*)') && str_contains($q['sql'], 'from "content_blocks"'));

    expect($retry)->toBe('library_full')
        ->and(raceBlockCount())->toBe(200)
        ->and(DB::connection('pgsql_admin')->table('content_blocks')->where('site_id', 1)->where('name', 'like', 'Khối thứ%')->pluck('name')->all())->toBe(['Khối thứ nhất'])
        ->and($lock)->toBeInt()
        ->and($count)->toBeInt()
        ->and($lock)->toBeLessThan($count)
        ->and($queries[$lock]['level'] ?? 0)->toBeGreaterThan(0);
});

it('makes a copy wait for a create holding the lock, then refuses it at 200', function (): void {
    /** @var User $editor */
    $editor = $this->editor;
    $source = app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->where('key', 'khoi-co-san-1')->firstOrFail());

    $first = onAdminConnection($editor);
    $whileOpen = raceWhileFirstOpen(
        fn () => app(CreateContentBlock::class)->handle($first, 'Khối thứ nhất'),
        fn () => app(DuplicateContentBlock::class)->handle($editor, $source),
    );
    $retry = raceOnAppConnection(fn () => app(DuplicateContentBlock::class)->handle($editor, $source));

    expect($whileOpen)->toBe('55P03')
        ->and($retry)->toBe('library_full')
        ->and(raceBlockCount())->toBe(200);
});

it('does not hold up a create on another website', function (): void {
    /** @var User $editor */
    $editor = $this->editor;
    $other = blockLibraryUser(2, 'editor');

    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $first = onAdminConnection($editor);
        $key = app(Tenancy::class)->enter(1, function () use ($first, $other): ?string {
            app(CreateContentBlock::class)->handle($first, 'Khối thứ nhất');

            DB::setDefaultConnection('pgsql');
            try {
                return app(Tenancy::class)->enter(2, function () use ($other): string {
                    DB::statement("set local lock_timeout = '300ms'");

                    return app(CreateContentBlock::class)->handle($other, 'Chân trang')->key;
                });
            } finally {
                DB::setDefaultConnection('pgsql_admin');
            }
        });
    } finally {
        DB::setDefaultConnection($previous);
    }

    expect($key)->toBe('chan-trang');
});
