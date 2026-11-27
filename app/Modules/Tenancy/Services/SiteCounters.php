<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of `site_counters`, the current website's running totals. A module counts its own
 * changes through it, in the same transaction as the change (an explicit call in the Action that
 * makes it, never a model observer: a bulk query skips observers), and recounts them through a
 * Shared\Contracts\CounterReconciler that `counters:reconcile` runs nightly.
 *
 *  - Every change is one upsert on the row (INSERT … ON CONFLICT DO UPDATE): never read then written,
 *    so two requests of one website changing the same total wait for each other on its row and both
 *    count. The row stays locked until the caller's transaction ends: the caller makes this its last
 *    write, so the wait stays short. A change waits at most LOCK_WAIT for another's hold on the row
 *    (lock_timeout, unless the caller already bounds its waits), then fails with lock_not_available
 *    instead of holding the request: no other holder should keep the row that long.
 *  - A total never goes below 0: GREATEST(value + delta, 0). A drift that would have taken it lower
 *    is corrected by the nightly recount.
 *  - tryAdd() is the same upsert with a ceiling: it adds only while the total stays at or under it,
 *    in the same statement, so two requests near the ceiling never both pass.
 *  - The website is named explicitly (site_id = ?) on top of RLS, from the current tenant context;
 *    without one every method throws (CurrentSite::id()).
 *  - Rows are locked in metric order (addMany()), so two writers of several totals never deadlock on
 *    each other.
 *  - A recount never holds the rows: drift() reads the true value and the stored one in one statement
 *    (one snapshot), and the correction is added as a delta, so a change committed meanwhile still
 *    counts on top of it.
 */
final readonly class SiteCounters
{
    /** The longest a change waits for another transaction's hold on its counter row (lock_timeout). */
    public const string LOCK_WAIT = '3s';

    public function __construct(private CurrentSite $current) {}

    /** Add $delta (negative to take away) to the current website's $metric. */
    public function add(string $metric, int $delta, int $subject = 0): void
    {
        if ($delta === 0) {
            return;
        }

        $this->bounded(fn () => $this->upsert($metric, $delta, $subject));
    }

    /**
     * Several totals at once, in metric order.
     *
     * @param  array<string, int>  $deltas  metric => delta
     */
    public function addMany(array $deltas, int $subject = 0): void
    {
        $deltas = array_filter($deltas, fn (int $delta): bool => $delta !== 0);
        if ($deltas === []) {
            return;
        }
        ksort($deltas, SORT_STRING);

        $this->bounded(function () use ($deltas, $subject): void {
            foreach ($deltas as $metric => $delta) {
                $this->upsert($metric, $delta, $subject);
            }
        });
    }

    private function upsert(string $metric, int $delta, int $subject): void
    {
        DB::statement(
            'INSERT INTO site_counters (site_id, metric, subject_id, value, updated_at) VALUES (?, ?, ?, GREATEST(?::bigint, 0), now())
            ON CONFLICT (site_id, metric, subject_id) DO UPDATE SET value = GREATEST(site_counters.value + ?::bigint, 0), updated_at = now()',
            [$this->current->id(), $metric, $subject, $delta, $delta],
        );
    }

    /**
     * Add $delta only when the total stays at or under $ceiling afterwards; false (and nothing
     * added) when it would go over. A delta of 0 or less always goes through.
     */
    public function tryAdd(string $metric, int $delta, int $ceiling, int $subject = 0): bool
    {
        if ($delta <= 0) {
            $this->add($metric, $delta, $subject);

            return true;
        }

        // No row yet: inserted only when the delta alone fits. A row already there: updated only
        // when its value plus the delta fits (ON CONFLICT re-reads the newest committed value).
        $rows = $this->bounded(fn (): array => DB::select(
            'INSERT INTO site_counters (site_id, metric, subject_id, value, updated_at)
            SELECT ?, ?, ?, ?::bigint, now() WHERE ?::bigint <= ?::bigint
            ON CONFLICT (site_id, metric, subject_id) DO UPDATE SET value = site_counters.value + EXCLUDED.value, updated_at = now()
                WHERE site_counters.value + EXCLUDED.value <= ?::bigint
            RETURNING value',
            [$this->current->id(), $metric, $subject, $delta, $delta, $ceiling, $ceiling],
        ));

        return $rows !== [];
    }

    /**
     * The current website's totals of these metrics (0 for one never counted), read in one
     * statement, with one more scalar SQL expression of the caller's in the same row when given
     * (a limit read beside the total it caps).
     *
     * @param  list<string>  $metrics
     * @param  array{0: string, 1: list<int|string>}|null  $also  the SQL expression and its bindings
     * @return array{values: array<string, int>, also: mixed}
     */
    public function read(array $metrics, ?array $also = null, int $subject = 0): array
    {
        $site = $this->current->id();
        $columns = [];
        $bindings = [];
        foreach ($metrics as $i => $metric) {
            $columns[] = "(SELECT value FROM site_counters WHERE site_id = ? AND metric = ? AND subject_id = ?) AS m{$i}";
            array_push($bindings, $site, $metric, $subject);
        }
        if ($also !== null) {
            $columns[] = "({$also[0]}) AS also";
            array_push($bindings, ...$also[1]);
        }
        if ($columns === []) {
            return ['values' => [], 'also' => null];
        }

        $row = (array) DB::selectOne('SELECT '.implode(', ', $columns), $bindings);
        $values = [];
        foreach ($metrics as $i => $metric) {
            $values[$metric] = Cast::int($row["m{$i}"] ?? 0);
        }

        return ['values' => $values, 'also' => $row['also'] ?? null];
    }

    /**
     * How far the current website's stored $metrics are from their true values, for a recount:
     * $recount is one SELECT of the caller's returning one row with a column per metric, named m0,
     * m1, … in $metrics order. It runs in the same statement as the read of the stored totals, so
     * both come from one snapshot: a change committed before it is in both, one committed after it
     * in neither. Adding true − stored (add()) then corrects the total without locking it, and a
     * change committed meanwhile still counts on top.
     *
     * @param  list<string>  $metrics
     * @param  array{0: string, 1: list<int|string>}  $recount  the SQL and its bindings
     * @return array<string, array{stored: int, true: int}>
     */
    public function drift(array $metrics, array $recount, int $subject = 0): array
    {
        if ($metrics === []) {
            return [];
        }

        $site = $this->current->id();
        $columns = [];
        $bindings = [];
        foreach ($metrics as $i => $metric) {
            $columns[] = "r.m{$i} AS t{$i}, coalesce((SELECT value FROM site_counters WHERE site_id = ? AND metric = ? AND subject_id = ?), 0) AS s{$i}";
            array_push($bindings, $site, $metric, $subject);
        }
        array_push($bindings, ...$recount[1]);

        $row = (array) DB::selectOne('SELECT '.implode(', ', $columns)." FROM ({$recount[0]}) AS r", $bindings);
        $out = [];
        foreach ($metrics as $i => $metric) {
            $out[$metric] = ['stored' => Cast::int($row["s{$i}"] ?? 0), 'true' => Cast::int($row["t{$i}"] ?? 0)];
        }

        return $out;
    }

    /**
     * Runs $write with lock_timeout at LOCK_WAIT when the transaction has no bound of its own, and
     * puts the setting back afterwards (a failed write ends the transaction, and the setting with it).
     * The old value is read in the statement that changes it, behind OFFSET 0, so it is read first.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function bounded(Closure $write): mixed
    {
        $row = (array) DB::selectOne(
            "SELECT b.v AS before, set_config('lock_timeout', CASE b.v WHEN '0' THEN ? ELSE b.v END, true) AS bound
            FROM (SELECT current_setting('lock_timeout') AS v OFFSET 0) AS b",
            [self::LOCK_WAIT],
        );
        $before = Cast::string($row['before'] ?? '');
        $result = $write();
        if ($before === '0') {
            DB::select("SELECT set_config('lock_timeout', '0', true)");
        }

        return $result;
    }
}
