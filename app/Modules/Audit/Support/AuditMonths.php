<?php

declare(strict_types=1);

namespace App\Modules\Audit\Support;

use App\Modules\Audit\AuditService;
use App\Modules\Audit\Exceptions\AuditMonthRefused;
use App\Modules\Shared\Support\Cast;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The audit log's months (one table per calendar month, UTC), through the functions the
 * partitioning migration created: the app may not create or drop a table itself. Months are
 * written 'YYYY-MM'. The running month comes from the database clock, the one its functions use.
 *
 * Dropping months and writing the audit row that says so happen in one transaction, with an
 * insert that does not swallow its failure: a month is never gone without its row.
 */
final class AuditMonths
{
    /** The scheduled prune keeps the running month and this many full months before it. */
    public const int KEEP_MONTHS = 24;

    /** A month removed by hand must be older than the running month and this many before it. */
    public const int MANUAL_FLOOR_MONTHS = 12;

    /** Months ensured ahead of the running one; fewer than WARN_AHEAD left means writes are at risk. */
    public const int AHEAD = 3;

    public const int WARN_AHEAD = 2;

    public function __construct(private readonly AuditService $audit) {}

    /** Creates the running month and the next AHEAD ones where missing; returns how many it made. */
    public function ensure(): int
    {
        return Cast::int(DB::scalar('select public.audit_log_months_ensure()'));
    }

    /**
     * Every month that exists, oldest first, with the planner's row estimate (not an exact count).
     *
     * @return list<array{month: string, rows_estimate: int}>
     */
    public function months(): array
    {
        $months = [];
        foreach (DB::select("select to_char(month, 'YYYY-MM') as month, rows_estimate from public.audit_log_months()") as $row) {
            $values = is_object($row) ? get_object_vars($row) : [];
            $months[] = ['month' => Cast::string($values['month'] ?? null), 'rows_estimate' => Cast::int($values['rows_estimate'] ?? null)];
        }

        return $months;
    }

    /** How many months exist after the running one. */
    public function monthsAhead(): int
    {
        $running = $this->runningMonth();

        return count(array_filter($this->months(), fn (array $m): bool => $m['month'] > $running));
    }

    /** The exact number of rows in one month, for the confirmation that names it. */
    public function rows(string $month): int
    {
        return Cast::int(DB::scalar('select public.audit_log_month_count(?::date)', [self::firstDay($month)]));
    }

    public function runningMonth(): string
    {
        return Cast::string(DB::scalar("select to_char(now() at time zone 'UTC', 'YYYY-MM')"));
    }

    /** May this month be removed by hand (older than the running month and the 12 before it)? */
    public function isRemovable(string $month): bool
    {
        return self::isMonth($month) && $month < $this->monthsBack(self::MANUAL_FLOOR_MONTHS);
    }

    /**
     * The months the scheduled prune removes: older than the running month and the 24 before it.
     *
     * @return list<string>
     */
    public function expired(): array
    {
        $cutoff = $this->monthsBack(self::KEEP_MONTHS);

        return array_values(array_map(
            fn (array $m): string => $m['month'],
            array_filter($this->months(), fn (array $m): bool => $m['month'] < $cutoff),
        ));
    }

    /**
     * Drops the months and records $action with their row count, in one transaction.
     *
     * @param  list<string>  $months
     * @param  array<string, mixed>  $context  AuditService context; `rows` is added to its meta
     * @return int the rows removed
     *
     * @throws AuditMonthRefused
     */
    public function drop(array $months, string $action, array $context = []): int
    {
        foreach ($months as $month) {
            if (! self::isMonth($month)) {
                throw new AuditMonthRefused('invalid');
            }
        }

        try {
            return DB::transaction(function () use ($months, $action, $context): int {
                $rows = 0;
                foreach ($months as $month) {
                    $rows += Cast::int(DB::scalar('select public.audit_log_month_drop(?::date)', [self::firstDay($month)]));
                }

                $meta = is_array($context['meta'] ?? null) ? $context['meta'] : [];
                $this->audit->recordOrFail($action, ['meta' => [...$meta, 'rows' => $rows]] + $context);

                return $rows;
            });
        } catch (QueryException $e) {
            $reason = match ($e->errorInfo[0] ?? null) {
                '42501' => 'kept',
                '55P03' => 'busy',
                '22023' => 'invalid',
                default => null,
            };

            throw $reason === null ? $e : new AuditMonthRefused($reason, $e);
        }
    }

    public static function isMonth(string $month): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1;
    }

    private static function firstDay(string $month): string
    {
        return $month.'-01';
    }

    /** The month $count months before the running one, 'YYYY-MM'. */
    private function monthsBack(int $count): string
    {
        return Cast::string(DB::scalar(
            "select to_char(date_trunc('month', now() at time zone 'UTC') - make_interval(months => ?), 'YYYY-MM')",
            [$count],
        ));
    }
}
