<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
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
 *    write, so the wait stays short.
 *  - A total never goes below 0: GREATEST(value + delta, 0). A drift that would have taken it lower
 *    is corrected by the nightly recount.
 *  - tryAdd() is the same upsert with a ceiling: it adds only while the total stays at or under it,
 *    in the same statement, so two requests near the ceiling never both pass.
 *  - The website is named explicitly (site_id = ?) on top of RLS, from the current tenant context;
 *    without one every method throws (CurrentSite::id()).
 *  - Rows are locked in metric order (addMany(), lock()), so two writers of several totals never
 *    deadlock on each other.
 */
final readonly class SiteCounters
{
    public function __construct(private CurrentSite $current) {}

    /** Add $delta (negative to take away) to the current website's $metric. */
    public function add(string $metric, int $delta, int $subject = 0): void
    {
        if ($delta === 0) {
            return;
        }

        DB::statement(
            'INSERT INTO site_counters (site_id, metric, subject_id, value, updated_at) VALUES (?, ?, ?, GREATEST(?::bigint, 0), now())
            ON CONFLICT (site_id, metric, subject_id) DO UPDATE SET value = GREATEST(site_counters.value + ?::bigint, 0), updated_at = now()',
            [$this->current->id(), $metric, $subject, $delta, $delta],
        );
    }

    /**
     * Several totals at once, in metric order.
     *
     * @param  array<string, int>  $deltas  metric => delta
     */
    public function addMany(array $deltas, int $subject = 0): void
    {
        ksort($deltas, SORT_STRING);
        foreach ($deltas as $metric => $delta) {
            $this->add($metric, $delta, $subject);
        }
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
        $rows = DB::select(
            'INSERT INTO site_counters (site_id, metric, subject_id, value, updated_at)
            SELECT ?, ?, ?, ?::bigint, now() WHERE ?::bigint <= ?::bigint
            ON CONFLICT (site_id, metric, subject_id) DO UPDATE SET value = site_counters.value + EXCLUDED.value, updated_at = now()
                WHERE site_counters.value + EXCLUDED.value <= ?::bigint
            RETURNING value',
            [$this->current->id(), $metric, $subject, $delta, $delta, $ceiling, $ceiling],
        );

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
     * Lock the current website's rows of these metrics (created at 0 when missing) until the
     * transaction ends, in metric order, and return their values: a recount taken while they are
     * held sees every change that has counted itself, and no change can count itself meanwhile.
     *
     * @param  list<string>  $metrics
     * @return array<string, int>
     */
    public function lock(array $metrics, int $subject = 0): array
    {
        $metrics = array_values(array_unique($metrics));
        sort($metrics, SORT_STRING);
        if ($metrics === []) {
            return [];
        }

        $site = $this->current->id();
        $tuples = [];
        $bindings = [];
        foreach ($metrics as $metric) {
            $tuples[] = '(?, ?, ?, 0, now())';
            array_push($bindings, $site, $metric, $subject);
        }
        // the no-op update takes the row lock and returns the value as it is
        $rows = DB::select(
            'INSERT INTO site_counters (site_id, metric, subject_id, value, updated_at) VALUES '.implode(', ', $tuples).'
            ON CONFLICT (site_id, metric, subject_id) DO UPDATE SET value = site_counters.value
            RETURNING metric, value',
            $bindings,
        );

        $values = array_fill_keys($metrics, 0);
        foreach ($rows as $row) {
            $row = (array) $row;
            $values[Cast::string($row['metric'] ?? '')] = Cast::int($row['value'] ?? 0);
        }

        return $values;
    }

    /** Write the current website's $metric as $value (a recount's correction, under lock()). */
    public function set(string $metric, int $value, int $subject = 0): void
    {
        DB::update(
            'UPDATE site_counters SET value = ?, updated_at = now() WHERE site_id = ? AND metric = ? AND subject_id = ?',
            [max(0, $value), $this->current->id(), $metric, $subject],
        );
    }
}
