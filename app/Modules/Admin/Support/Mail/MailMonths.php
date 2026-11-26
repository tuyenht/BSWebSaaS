<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\DB;

/**
 * The mail log's months (one table per calendar month, UTC) and the daily counts' retention, through
 * the functions the mail log migration created: the app may not create, drop or delete itself.
 * A mail is kept 90 days: a month goes once all of it is older than that, a day count once its day is.
 * The database refuses the running month and the three before it, and any day of the last 90,
 * whatever it is asked. Months are written 'YYYY-MM'; the clock is the database's.
 */
final class MailMonths
{
    public const int KEEP_DAYS = 90;

    /** Months ensured ahead of the running one; fewer than WARN_AHEAD ready means writes are at risk. */
    public const int WARN_AHEAD = 2;

    /** Creates the running month and the next three where missing; returns how many it made. */
    public function ensure(): int
    {
        return Cast::int(DB::scalar('select public.mail_log_months_ensure()'));
    }

    /** @return list<string> every month that exists, oldest first */
    public function months(): array
    {
        return array_values(array_map(
            fn (mixed $month): string => Cast::string($month),
            DB::table(DB::raw('public.mail_log_months() as m'))->selectRaw("to_char(m.month, 'YYYY-MM') as month")->pluck('month')->all(),
        ));
    }

    /** How many months in a row are ready after the running one (0 when the running month is missing). */
    public function monthsAhead(): int
    {
        $ready = $this->months();
        $ahead = -1;
        while (in_array(Cast::string(DB::scalar(
            "select to_char(date_trunc('month', now() at time zone 'UTC') + make_interval(months => ?), 'YYYY-MM')",
            [$ahead + 1],
        )), $ready, true)) {
            $ahead++;
        }

        return max(0, $ahead);
    }

    /**
     * The months whose whole span is older than KEEP_DAYS: they end at or before that day (UTC).
     *
     * @return list<string>
     */
    public function expired(): array
    {
        $cutoff = Cast::string(DB::scalar(
            "select to_char((now() at time zone 'UTC')::date - make_interval(days => ?), 'YYYY-MM-DD')",
            [self::KEEP_DAYS],
        ));

        return array_values(array_filter(
            $this->months(),
            fn (string $month): bool => date('Y-m-d', (int) strtotime($month.'-01 +1 month')) <= $cutoff,
        ));
    }

    /** Drops one month (its own transaction); false when it was gone already. */
    public function drop(string $month): bool
    {
        return DB::transaction(fn (): bool => (bool) DB::scalar('select public.mail_log_month_drop(?::date)', [$month.'-01']));
    }

    /** Removes the day counts older than KEEP_DAYS, every website's; returns how many. */
    public function pruneDays(): int
    {
        return Cast::int(DB::transaction(fn (): mixed => DB::scalar(
            "select public.mail_daily_prune(((now() at time zone 'UTC')::date - make_interval(days => ?))::date)",
            [self::KEEP_DAYS],
        )));
    }
}
