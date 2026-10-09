<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The counting day of the error notices — the ONE definition the counters and the daily summary
 * share. It runs from the summary time (config monitoring.summary_at, 08:00) in the operator's
 * zone (monitoring.timezone) to the same time the next day, so the morning summary always covers
 * exactly the day that just closed. Named by the local date it starts on (Ymd).
 */
final readonly class DayWindow
{
    public function id(CarbonInterface $at): string
    {
        return $this->start($at)->format('Ymd');
    }

    /** The day that closed most recently before $at — what the summary sent at $at reports on. */
    public function previous(CarbonInterface $at): string
    {
        return $this->start($at)->subDay()->format('Ymd');
    }

    /** Local start of the counting day $id. */
    public function startOf(string $id): CarbonImmutable
    {
        [$hour, $minute] = $this->startTime();
        $date = CarbonImmutable::createFromFormat('!Ymd', $id, $this->zone());

        return ($date instanceof CarbonImmutable ? $date : CarbonImmutable::now($this->zone()))->setTime($hour, $minute);
    }

    private function start(CarbonInterface $at): CarbonImmutable
    {
        [$hour, $minute] = $this->startTime();
        $local = CarbonImmutable::instance($at)->setTimezone($this->zone());
        $start = $local->setTime($hour, $minute);

        return $local->lt($start) ? $start->subDay() : $start;
    }

    private function zone(): string
    {
        $zone = config('monitoring.timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    /** @return array{int, int} */
    private function startTime(): array
    {
        $at = config('monitoring.summary_at');

        if (is_string($at) && preg_match('/\A(\d{1,2}):(\d{2})\z/', $at, $match) === 1) {
            return [(int) $match[1], (int) $match[2]];
        }

        return [8, 0];
    }
}
