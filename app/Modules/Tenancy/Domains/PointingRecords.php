<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use App\Modules\Shared\Support\Cast;

/**
 * The DNS rows a customer adds where they bought a name, so both spellings of it (with and without
 * `www.`) reach the platform (design.md §28): one row per spelling, written under the registered
 * name (`@` for the name itself, `www`, `shop`, `www.shop`…). Every row follows the target host
 * by name; only the registered name itself may instead use the server's address, for the
 * registrars that cannot point `@` at a name.
 */
final class PointingRecords
{
    /**
     * @param  string  $runs  the spelling that runs
     * @param  string|null  $alt  the spelling that redirects to it
     * @param  string|null  $ip  the server's address, when the owner points `@` by number
     * @return list<array{host: string, label: string, kind: 'name'|'ip', target: string, runs: bool}>
     */
    public static function for(string $runs, ?string $alt, ?string $ip = null): array
    {
        $registered = DomainName::registrable(DomainName::pairKey($runs));
        $records = [];

        foreach (array_filter([$runs, $alt]) as $host) {
            $label = $host === $registered ? '@' : substr($host, 0, strlen($host) - strlen($registered) - 1);
            $byNumber = $label === '@' && $ip !== null && $ip !== '';

            $records[] = [
                'host' => $host,
                'label' => $label,
                'kind' => $byNumber ? 'ip' : 'name',
                'target' => $byNumber ? (string) $ip : self::target(),
                'runs' => $host === $runs,
            ];
        }

        return $records;
    }

    /** The one host every customer domain points at. */
    public static function target(): string
    {
        return Cast::string(config('tenancy.custom_domains.target'));
    }
}
