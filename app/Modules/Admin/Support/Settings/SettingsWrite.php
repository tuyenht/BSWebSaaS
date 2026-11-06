<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Tenancy\Support\SiteWriteLock;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The transaction every settings save made from an admin screen runs in: it waits for the site's
 * settings lock (SiteWriteLock) at most LOCK_TIMEOUT, then gives up with a plain-language "busy"
 * refusal instead of leaving the screen frozen behind a long writer (a content-pack apply holds the
 * same lock for its whole run). The screen keeps what the admin typed and says to try again.
 *
 * The limit is set with SET LOCAL as the first statement, before the lock is taken, so it covers
 * that lock and every row lock the save takes after it. Request transactions are savepoints inside
 * the request's own (Tenancy::enter), and SET LOCAL outlives a released savepoint, so the value the
 * request had is put back once the save is done; a save that fails rolls back to its savepoint,
 * which undoes the SET LOCAL by itself. Console commands take the lock without this and keep
 * waiting as long as it takes.
 */
final class SettingsWrite
{
    /**
     * An ordinary save holds the lock for milliseconds, so 5s still lets a queue of several saves
     * through, while a long apply is answered in seconds rather than at the request's own timeout.
     */
    public const string LOCK_TIMEOUT = '5s';

    /** lock_not_available: a lock wait reached lock_timeout. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    /**
     * @template TReturn
     *
     * @param  string  $field  what the refusal is about (the section, or the writer's name)
     * @param  Closure():TReturn  $write  runs with the site's settings lock held
     * @return TReturn
     *
     * @throws SettingsChangeRejected busy, when a lock was not free within LOCK_TIMEOUT
     */
    public static function run(string $field, Closure $write): mixed
    {
        try {
            return DB::transaction(function () use ($write): mixed {
                $previous = DB::scalar("select current_setting('lock_timeout')");
                DB::statement("set local lock_timeout = '".self::LOCK_TIMEOUT."'");
                SiteWriteLock::take();   // then the lock: one row that always exists, in every writer the same order

                $result = $write();

                DB::select("select set_config('lock_timeout', ?, true)", [is_string($previous) ? $previous : '0']);

                return $result;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === self::LOCK_NOT_AVAILABLE) {
                throw new SettingsChangeRejected('busy', $field, $e);
            }

            throw $e;
        }
    }
}
