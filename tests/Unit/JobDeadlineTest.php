<?php

use App\Modules\Cms\Support\JobDeadline;
use Carbon\CarbonImmutable;

/*
 * The deadline state of a job post: open, closing soon, today, expired or no date. Only the day
 * counts, in the site's time zone.
 */

it('names the state of a deadline from the day it is seen', function (string $deadline, string $expected): void {
    $today = CarbonImmutable::parse('2026-11-07 23:30', 'Asia/Ho_Chi_Minh');

    expect((new JobDeadline)->state(CarbonImmutable::parse($deadline, 'Asia/Ho_Chi_Minh'), $today))->toBe($expected);
})->with([
    'yesterday' => ['2026-11-06 23:59', JobDeadline::EXPIRED],
    'later today' => ['2026-11-07 00:00', JobDeadline::TODAY],
    'tomorrow' => ['2026-11-08 08:00', JobDeadline::CLOSING_SOON],
    'in seven days' => ['2026-11-14 08:00', JobDeadline::CLOSING_SOON],
    'in eight days' => ['2026-11-15 08:00', JobDeadline::OPEN],
]);

it('has no date when the post carries none', function (): void {
    expect((new JobDeadline)->state(null, CarbonImmutable::now()))->toBe(JobDeadline::NO_DATE);
});
