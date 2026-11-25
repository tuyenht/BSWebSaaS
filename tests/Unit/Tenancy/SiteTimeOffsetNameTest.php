<?php

declare(strict_types=1);

use App\Modules\Tenancy\Context\SiteTime;

it('names an offset from UTC the way the editors print it beside a time', function (int $seconds, string $label): void {
    expect(SiteTime::offsetName($seconds))->toBe($label);
})->with([
    'Hà Nội, +07:00' => [7 * 3600, 'GMT+7'],
    'India, +05:30' => [5 * 3600 + 30 * 60, 'GMT+5:30'],
    'UTC, +00:00' => [0, 'GMT+0'],
    'São Paulo, -03:00' => [-3 * 3600, 'GMT-3'],
]);
