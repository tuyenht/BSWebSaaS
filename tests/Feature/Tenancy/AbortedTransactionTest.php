<?php

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use App\Modules\Tenancy\Models\TenantProbe;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\MarkAfterCommitJob;
use Tests\Fixtures\SwallowFailureJob;

/*
 * Gate #1 — a statement that fails inside Tenancy::enter aborts the whole PostgreSQL transaction,
 * even when the code catches the error and carries on, and PostgreSQL answers the closing COMMIT with
 * a silent ROLLBACK. enter() must then take Laravel's rollback path: nothing persists, after-commit
 * work (jobs, mail, file deletes) never runs, after-rollback cleanups do, one warning is logged and
 * the caller gets the same answer. A failure recovered through a savepoint commits as before, and a
 * request with no failure sends exactly the statements it sent before (Query Budget unchanged).
 */

beforeEach(fn () => seedTenancy());

afterEach(fn () => app(CurrentSite::class)->clear());

/** Runs a statement PostgreSQL refuses (division by zero, SQLSTATE 22012) and swallows the error. */
function abortedTxSwallowFailure(): void
{
    try {
        DB::select('select 1/0');
    } catch (QueryException) {
        // swallowed on purpose, as many app paths do
    }
}

/** The probe rows actually stored, read past RLS. @return list<string> */
function abortedTxStoredProbes(): array
{
    return DB::connection('pgsql_admin')->table('tenant_probes')->orderBy('id')->pluck('value')->all();
}

/** Expects exactly one rollback warning for $siteId naming the first failure's SQLSTATE. */
function abortedTxExpectWarning(int $siteId, int $times = 1): void
{
    Log::shouldHaveReceived('warning')
        ->times($times)
        ->withArgs(fn (string $message, array $context): bool => $context === ['site_id' => $siteId, 'sqlstate' => '22012']);
}

it('rolls back and runs rollback callbacks when a swallowed statement aborted the site\'s transaction', function (): void {
    Log::spy();
    $committed = false;
    $rolledBack = false;

    $result = app(Tenancy::class)->enter(1, function () use (&$committed, &$rolledBack): string {
        TenantProbe::create(['value' => 'lost']);
        DB::afterCommit(function () use (&$committed): void {
            $committed = true;
        });
        DB::afterRollBack(function () use (&$rolledBack): void {
            $rolledBack = true;
        });
        abortedTxSwallowFailure();

        return 'the answer';
    });

    expect($result)->toBe('the answer')
        ->and($committed)->toBeFalse()
        ->and($rolledBack)->toBeTrue()
        ->and(abortedTxStoredProbes())->toBe([])
        ->and(DB::transactionLevel())->toBe(0)
        ->and(app(CurrentSite::class)->hasId())->toBeFalse();
    abortedTxExpectWarning(1);
});

it('commits as before when a failure was recovered through a savepoint', function (): void {
    Log::spy();
    $committed = false;
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(Tenancy::class)->enter(1, function () use (&$committed): void {
        TenantProbe::create(['value' => 'before']);
        try {
            DB::transaction(fn () => DB::select('select 1/0'));
        } catch (QueryException) {
            // the savepoint was rolled back: the transaction is healthy again
        }
        TenantProbe::create(['value' => 'after']);
        DB::afterCommit(function () use (&$committed): void {
            $committed = true;
        });
    });

    $probes = collect(DB::getQueryLog())->where('query', 'select 1')->count();
    DB::disableQueryLog();

    expect(abortedTxStoredProbes())->toBe(['before', 'after'])
        ->and($committed)->toBeTrue()
        ->and($probes)->toBe(1);
    Log::shouldNotHaveReceived('warning');
});

it('sends no extra statement on a healthy enter', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(Tenancy::class)->enter(1, fn () => TenantProbe::create(['value' => 'kept']));

    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($queries)->toHaveCount(3)
        ->and($queries[0])->toBe('select set_config(?, ?, true)')
        ->and($queries[1])->toBe("select current_setting('app.current_site_id', true)")
        ->and($queries[2])->toStartWith('insert into "tenant_probes"')
        ->and(abortedTxStoredProbes())->toBe(['kept']);
});

it('rolls back only its own savepoint when enter is nested in an open transaction', function (): void {
    Log::spy();
    $tenancy = app(Tenancy::class);
    $outerCommitted = false;
    $innerCommitted = false;
    $innerRolledBack = false;

    DB::transaction(function () use ($tenancy, &$outerCommitted, &$innerCommitted, &$innerRolledBack): void {
        $tenancy->enter(1, fn () => TenantProbe::create(['value' => 'kept']));

        $answer = $tenancy->enter(2, function () use (&$innerCommitted, &$innerRolledBack): string {
            TenantProbe::create(['value' => 'lost']);
            DB::afterCommit(function () use (&$innerCommitted): void {
                $innerCommitted = true;
            });
            DB::afterRollBack(function () use (&$innerRolledBack): void {
                $innerRolledBack = true;
            });
            abortedTxSwallowFailure();

            return 'inner answer';
        });
        expect($answer)->toBe('inner answer')
            ->and(DB::transactionLevel())->toBe(1);

        $tenancy->enter(1, fn () => TenantProbe::create(['value' => 'kept too']));
        DB::afterCommit(function () use (&$outerCommitted): void {
            $outerCommitted = true;
        });
    });

    expect(abortedTxStoredProbes())->toBe(['kept', 'kept too'])
        ->and($outerCommitted)->toBeTrue()
        ->and($innerCommitted)->toBeFalse()
        ->and($innerRolledBack)->toBeTrue();
    abortedTxExpectWarning(2);
});

it('turns an uncaught action error into a 500 without running after-commit work', function (): void {
    Log::spy();
    Route::middleware(SetCurrentSite::class)->get('/_aborted/uncaught', function (): never {
        TenantProbe::create(['value' => 'lost']);
        MarkAfterCommitJob::dispatch('uncaught');
        DB::afterRollBack(static fn () => Cache::put('rollback-cleanup:uncaught', true));
        DB::select('select 1/0');

        throw new LogicException('unreachable');
    });

    $this->get('http://a.test/_aborted/uncaught')->assertStatus(500);

    expect(Cache::has('after-commit-job:uncaught'))->toBeFalse()
        ->and(Cache::has('rollback-cleanup:uncaught'))->toBeTrue()
        ->and(abortedTxStoredProbes())->toBe([]);
    abortedTxExpectWarning(1);
});

it('answers the app\'s own response and drops after-commit work when a controller swallows the error', function (): void {
    Log::spy();
    Route::middleware(SetCurrentSite::class)->get('/_aborted/swallowed', function () {
        TenantProbe::create(['value' => 'lost']);
        MarkAfterCommitJob::dispatch('swallowed');
        DB::afterRollBack(static fn () => Cache::put('rollback-cleanup:swallowed', true));
        abortedTxSwallowFailure();

        return response('the app\'s own answer', 200);
    });

    $this->get('http://a.test/_aborted/swallowed')->assertOk()->assertContent('the app\'s own answer');

    expect(Cache::has('after-commit-job:swallowed'))->toBeFalse()
        ->and(Cache::has('rollback-cleanup:swallowed'))->toBeTrue()
        ->and(abortedTxStoredProbes())->toBe([]);
    abortedTxExpectWarning(1);
});

it('drops a WithinSite job\'s after-commit work when the job swallowed a failure', function (): void {
    Log::spy();

    SwallowFailureJob::dispatchSync(1);

    expect(Cache::has('after-commit-job:swallowing-job'))->toBeFalse()
        ->and(Cache::has('rollback-cleanup:swallowing-job'))->toBeTrue()
        ->and(abortedTxStoredProbes())->toBe([]);
    abortedTxExpectWarning(1);
});

it('an eachSite loop that catches a row\'s failure rolls back that site only', function (): void {
    Log::spy();
    $committed = [];

    $visited = app(Tenancy::class)->eachSite(function (int $siteId) use (&$committed): void {
        TenantProbe::create(['value' => 'site '.$siteId]);
        DB::afterCommit(function () use (&$committed, $siteId): void {
            $committed[] = $siteId;
        });

        if ($siteId === 1) {
            abortedTxSwallowFailure();
        }
    });

    expect($visited)->toBe(2)
        ->and($committed)->toBe([2])
        ->and(abortedTxStoredProbes())->toBe(['site 2']);
    abortedTxExpectWarning(1);
});

it('keeps a failure that escapes the work on Laravel\'s usual path: rolled back, rethrown, no probe', function (): void {
    Log::spy();
    $rolledBack = false;
    DB::flushQueryLog();
    DB::enableQueryLog();

    $thrown = null;
    try {
        app(Tenancy::class)->enter(1, function () use (&$rolledBack): void {
            TenantProbe::create(['value' => 'lost']);
            DB::afterRollBack(function () use (&$rolledBack): void {
                $rolledBack = true;
            });
            DB::select('select 1/0');
        });
    } catch (QueryException $e) {
        $thrown = $e;
    }

    $probes = collect(DB::getQueryLog())->where('query', 'select 1')->count();
    DB::disableQueryLog();

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and($rolledBack)->toBeTrue()
        ->and($probes)->toBe(0)
        ->and(abortedTxStoredProbes())->toBe([])
        ->and(DB::transactionLevel())->toBe(0);
    Log::shouldNotHaveReceived('warning');
});

it('rethrows a probe failure that is not the aborted-transaction answer instead of returning quietly', function (): void {
    Log::spy();
    $rolledBack = false;
    $armed = false;

    // The probe meets a lost connection (SQLSTATE 08006) instead of PostgreSQL's 25P02 answer. The hook
    // runs before Laravel sends the statement, so the real transaction is only rolled back afterwards.
    DB::beforeExecuting(function (string $query) use (&$armed): void {
        if ($armed && $query === 'select 1') {
            $armed = false;
            // PDO puts the SQLSTATE string in the code and in errorInfo; its constructor takes an int code only.
            $cause = new class('SQLSTATE[08006]: Connection failure: the server went away') extends PDOException
            {
                public function __construct(string $message)
                {
                    parent::__construct($message);
                    $this->code = '08006';
                    $this->errorInfo = ['08006', 7, 'the server went away'];
                }
            };

            throw new QueryException('pgsql', $query, [], $cause);
        }
    });

    $thrown = null;
    try {
        app(Tenancy::class)->enter(1, function () use (&$rolledBack, &$armed): string {
            TenantProbe::create(['value' => 'lost']);
            DB::afterRollBack(function () use (&$rolledBack): void {
                $rolledBack = true;
            });
            abortedTxSwallowFailure();
            $armed = true;

            return 'the answer';
        });
    } catch (QueryException $e) {
        $thrown = $e;
    } finally {
        $armed = false;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and($thrown?->getCode())->toBe('08006')
        ->and($rolledBack)->toBeTrue()
        ->and(abortedTxStoredProbes())->toBe([])
        ->and(DB::transactionLevel())->toBe(0)
        ->and(app(CurrentSite::class)->hasId())->toBeFalse();
    Log::shouldNotHaveReceived('warning');
});
