<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

/**
 * A browser's user-agent string as people read it: "Chrome · Android", "Safari · iPhone". Only the
 * well-known browsers and systems are named; null when neither is recognised (the caller says
 * "Thiết bị khác"). The raw string never reaches a screen.
 */
final class DeviceLabel
{
    public static function of(string $agent): ?string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox/'), str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/'), str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh'), str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Ubuntu') => 'Ubuntu',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
        $known = array_values(array_filter([$browser, $system]));

        return $known === [] ? null : implode(' · ', $known);
    }
}
