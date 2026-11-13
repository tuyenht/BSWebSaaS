<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Storefront\Models\ContactClickDaily;
use App\Modules\Tenancy\Context\SiteTime;

/**
 * The current website's contact button taps over its last days: totals per channel, per placement
 * and per day. One query over the (site_id, day, …) unique index — a day range of this website,
 * only the columns it needs; the rows are summed here (at most days × channels × placements).
 * Taps still in the Valkey buffer (up to five minutes old) are not in it yet.
 */
final readonly class ContactClickStats
{
    public function __construct(private SiteTime $time) {}

    /**
     * The last `days` days of the website's calendar, today included. `days` lists every day,
     * oldest first, with 0 for a day without taps; channels and placements list only those tapped,
     * most tapped first.
     *
     * @return array{total: int, channels: array<string, int>, placements: array<string, int>, days: array<string, int>}
     */
    public function lastDays(int $days = 30): array
    {
        $days = max(1, $days);
        $today = $this->time->now()->startOfDay();
        $from = $today->subDays($days - 1);

        $daily = [];
        for ($day = $from; $day <= $today; $day = $day->addDay()) {
            $daily[$day->toDateString()] = 0;
        }

        $rows = ContactClickDaily::query()
            ->toBase()
            ->select(['day', 'channel', 'placement', 'clicks'])
            ->where('day', '>=', $from->toDateString())
            ->where('day', '<=', $today->toDateString())
            ->get();

        $channels = [];
        $placements = [];
        foreach ($rows as $row) {
            $clicks = Cast::int($row->clicks);
            $channel = Cast::string($row->channel);
            $placement = Cast::string($row->placement);
            $day = substr(Cast::string($row->day), 0, 10);

            $channels[$channel] = ($channels[$channel] ?? 0) + $clicks;
            $placements[$placement] = ($placements[$placement] ?? 0) + $clicks;
            if (isset($daily[$day])) {
                $daily[$day] += $clicks;
            }
        }
        arsort($channels);
        arsort($placements);

        return [
            'total' => array_sum($channels),
            'channels' => $channels,
            'placements' => $placements,
            'days' => $daily,
        ];
    }
}
