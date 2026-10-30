<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Runs read queries under a time limit, so one slow search over a large global table (a free-text
 * match no index can serve) gives up instead of holding a worker and a pooled connection.
 *
 * The limit is `SET LOCAL statement_timeout` inside an explicit transaction: it ends with that
 * transaction, so it never reaches the next request on the same PgBouncer connection (transaction
 * mode). A plain `SET` would stay on the server connection and leak to whoever gets it next.
 * Inside an already open transaction it would only be a savepoint, and the limit would last to
 * the end of the outer transaction (writes included), so that case is refused.
 */
class BoundedRead
{
    /** PostgreSQL's query_canceled: the statement ran past statement_timeout. */
    public const string TIMED_OUT = '57014';

    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @param  int  $milliseconds  how long each statement may run
     * @return T
     */
    public function run(Closure $read, int $milliseconds = 3000): mixed
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('A bounded read needs its own transaction; it was called inside an open one.');
        }

        return DB::transaction(function () use ($read, $milliseconds): mixed {
            DB::statement('SET LOCAL statement_timeout = '.max(1, $milliseconds));

            return $read();
        });
    }

    public static function timedOut(QueryException $e): bool
    {
        return (string) $e->getCode() === self::TIMED_OUT;
    }
}
