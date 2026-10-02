<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use App\Modules\Shared\Support\Cast;

/**
 * Does a customer's domain point at the platform (design.md §28)? It does when it resolves, and
 * every address it resolves to is an address of the target host — so an old record still pointing
 * somewhere else (a forgotten IPv6 address, a second A record) counts as not pointing here: some
 * visitors would still land on the other server.
 */
final readonly class Pointing
{
    public function __construct(private DnsLookup $dns) {}

    /**
     * Null when the domain points at the platform, else why not.
     *
     * @return 'not_pointing'|'points_elsewhere'|null
     */
    public function problem(string $host): ?string
    {
        $addresses = $this->dns->addresses($host);

        if ($addresses === []) {
            return 'not_pointing';
        }

        $target = $this->dns->addresses(Cast::string(config('tenancy.custom_domains.target')));

        return array_diff($addresses, $target) === [] ? null : 'points_elsewhere';
    }
}
