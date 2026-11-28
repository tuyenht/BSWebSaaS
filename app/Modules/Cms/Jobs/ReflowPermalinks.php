<?php

declare(strict_types=1);

namespace App\Modules\Cms\Jobs;

use App\Modules\Cms\Actions\Permalinks\ChangeSitePermalinks;
use App\Modules\Cms\Actions\Permalinks\ReflowSitePermalinks;
use App\Modules\Cms\Exceptions\PermalinkMoveFailedException;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The background half of a change of address format («Đường dẫn»): moves the routes of every item
 * in the changed sections to the address the staged preset gives (ReflowSitePermalinks::chunk —
 * PathBuilder composes, RouteSync moves and leaves a 301 from the old address).
 *
 * The run keeps its place in the database, not in the job (PermalinkConfig::run(): phase, sections,
 * cursor, heartbeat, the items it could not move), written in the same transaction as each chunk's
 * moves. So any run started for the site carries on from where the last chunk stopped: a hand-over,
 * a retry, a run started again because the last progress is old (ChangeSitePermalinks::
 * resumeIfStale), or one more dispatched next to a queued one. Two never interleave a chunk (the
 * site's permalink lock) and a hand-over that the queue drops beside a waiting run loses nothing.
 *
 * Phases:
 *   1. move   — chunk after chunk (500 items), each in its own Tenancy::enter (an explicit
 *               transaction with SET LOCAL app.current_site_id) under the site's permalink lock,
 *               composing the staged sections from their pending preset. An address another item
 *               holds is left where it is: the holder may move away later in the run. Only the kinds
 *               the staged sections live in are walked. When every kind is done, the pending presets
 *               become the live ones (PermalinkConfig::promote) in the same transaction.
 *   2. settle — the same sections walked once more on the live preset: an item saved during the
 *               move composed its address from the old preset and is put right here, and an address
 *               still taken now gets the next free slug («-2»). The site stays busy (no other change
 *               starts) until settle ends; the items that could not move are kept for the screen.
 * A run works RUN_SECONDS and hands over to a fresh one, so no job holds a worker long.
 *
 * A chunk opens no savepoint, so a long run never piles up subtransactions. When one of its addresses
 * fails, the chunk's whole transaction rolls back (its cursor with it), a short transaction stores
 * that address in the run's `skip`, and the next step runs the same chunk again without it, reported
 * failed (ReflowSitePermalinks::chunk's `$skip`): the other items of the chunk still move, and settle
 * keeps the failed one for the screen. The skip is stored, so a hand-over never starts it over.
 */
final class ReflowPermalinks implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const string MOVE = PermalinkConfig::RUN_MOVE;

    public const string SETTLE = PermalinkConfig::RUN_SETTLE;

    /** Seconds one run works before it hands over to a fresh run. */
    public const int RUN_SECONDS = 30;

    /** Items that could not move kept for the screen (the count goes on). */
    public const int FAILURES_KEPT = 50;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** A queued run that is lost frees the site's place in the queue after this (below the stale window). */
    public int $uniqueFor = 600;

    public function __construct(
        public int $siteId,
        public int $size = ReflowSitePermalinks::CHUNK,
        public int $seconds = self::RUN_SECONDS,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'permalinks:'.$this->siteId;
    }

    public function failed(?Throwable $e): void
    {
        if ($e !== null) {
            report($e);
        }
    }

    /** True when the run finished; false when it handed over to a next run. */
    public function handle(Tenancy $tenancy, ReflowSitePermalinks $engine, PermalinkConfig $permalinks): bool
    {
        $stopAt = microtime(true) + $this->seconds;
        $kinds = $engine->kinds();

        while (true) {
            $at = '';
            try {
                $done = $tenancy->enter($this->siteId, function () use ($engine, $permalinks, $kinds, &$at): bool {
                    return $this->step($engine, $permalinks, $kinds, $at);
                });
            } catch (PermalinkMoveFailedException $e) {
                // the chunk rolled back whole: the next step, of this run or any later one, runs it again without that address
                $tenancy->enter($this->siteId, fn () => $this->skip($engine, $permalinks, $e, $at));
                $done = false;
            }
            if ($done) {
                return true;
            }
            if (microtime(true) >= $stopAt) {
                self::dispatch($this->siteId, $this->size, $this->seconds);

                return false;
            }
        }
    }

    /**
     * One chunk (or one phase change) from the stored cursor, the cursor and heartbeat written with
     * it. True when there is nothing left to do. $at is set to the chunk's place in the run; the run's
     * `skip` holds the addresses an earlier attempt at the chunk in that place failed on.
     *
     * @param  list<string>  $kinds
     */
    private function step(ReflowSitePermalinks $engine, PermalinkConfig $permalinks, array $kinds, string &$at): bool
    {
        ChangeSitePermalinks::lock($this->siteId);
        $permalinks->flush(); // read what the lock now guards
        $pending = $permalinks->pending();
        $run = $permalinks->run();
        $now = Carbon::now()->utc()->toIso8601String();

        if ($run === null || $run['phase'] === PermalinkConfig::RUN_DONE) {
            if ($pending === []) {
                return true;
            }
            $run = self::fresh($pending, $now);
        }
        $targets = [];
        if ($run['phase'] === self::MOVE) {
            if ($pending === []) { // the operator flattened everything meanwhile: nothing left to move
                $permalinks->saveRun(null);

                return true;
            }
            $targets = $pending;
            $run['sections'] = array_keys($pending);
        }

        while ($run['kind'] < count($kinds) && ! $engine->walks($kinds[$run['kind']], $run['sections'])) {
            [$run['kind'], $run['after']] = [$run['kind'] + 1, 0];
        }

        if ($run['kind'] >= count($kinds)) {
            if ($run['phase'] === self::MOVE) {
                $permalinks->promote();
                $permalinks->saveRun([...$run, 'phase' => self::SETTLE, 'kind' => 0, 'after' => 0, 'beat' => $now, 'skip' => ['at' => '', 'routes' => []]]);

                return false;
            }
            $permalinks->saveRun($run['failed'] > 0 ? [...$run, 'phase' => PermalinkConfig::RUN_DONE, 'beat' => $now] : null);

            return true;
        }

        $settle = $run['phase'] === self::SETTLE;
        $at = $run['phase'].':'.$run['kind'].':'.$run['after'];
        $chunk = $engine->chunk($kinds[$run['kind']], $run['sections'], $targets, $run['after'], $this->size, suffix: $settle, skip: $run['skip']['at'] === $at ? $run['skip']['routes'] : []);
        if ($settle) {
            foreach ($chunk['rows'] as $row) {
                if (in_array($row['status'], ['failed', 'conflict'], true)) {
                    $run['failed']++;
                    if (count($run['failures']) < self::FAILURES_KEPT) {
                        $run['failures'][] = [
                            'section' => (string) $row['section'], 'type' => $row['type'], 'title' => $row['title'], 'locale' => $row['locale'],
                            'old' => $row['old'], 'new' => $row['new'], 'reason' => $row['reason'] ?? ReflowSitePermalinks::ERROR,
                        ];
                    }
                }
            }
        }
        [$run['kind'], $run['after']] = $chunk['last'] === null ? [$run['kind'] + 1, 0] : [$run['kind'], $chunk['last']];
        $permalinks->saveRun([...$run, 'beat' => $now, 'skip' => ['at' => '', 'routes' => []]]);

        return false;
    }

    /**
     * Store, in a short transaction of its own, that the chunk at $at fails on the address $failed
     * names, so the next attempt (this run's or a later one's, after a hand-over) skips it: the
     * attempts at one chunk add up across runs and the chunk always gets through.
     */
    private function skip(ReflowSitePermalinks $engine, PermalinkConfig $permalinks, PermalinkMoveFailedException $failed, string $at): void
    {
        ChangeSitePermalinks::lock($this->siteId);
        $permalinks->flush();
        $run = $permalinks->run();
        $now = Carbon::now()->utc()->toIso8601String();
        if ($run === null || $run['phase'] === PermalinkConfig::RUN_DONE) {
            $pending = $permalinks->pending();
            if ($pending === []) {
                return;
            }
            $run = self::fresh($pending, $now); // the run the failed step began, rolled back with it
        }
        $routes = $engine->skipping($run['skip']['at'] === $at ? $run['skip']['routes'] : [], $failed);
        $permalinks->saveRun([...$run, 'beat' => $now, 'skip' => ['at' => $at, 'routes' => $routes]]);
    }

    /**
     * A new run over the staged sections, from the start.
     *
     * @param  array<string, array{pattern: string, base: string|null}>  $pending
     * @return array{phase: string, sections: list<string>, kind: int, after: int, beat: string, failures: list<array{section: string, type: string, title: string, locale: string, old: string, new: string, reason: string}>, failed: int, skip: array{at: string, routes: array<string, string>}}
     */
    private static function fresh(array $pending, string $now): array
    {
        return ['phase' => self::MOVE, 'sections' => array_keys($pending), 'kind' => 0, 'after' => 0, 'beat' => $now, 'failures' => [], 'failed' => 0, 'skip' => ['at' => '', 'routes' => []]];
    }
}
