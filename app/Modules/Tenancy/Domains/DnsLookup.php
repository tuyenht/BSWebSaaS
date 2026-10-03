<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

/**
 * What the platform needs to know from public DNS about a customer's domain. An interface so
 * tests answer without the network; SystemDnsLookup asks the resolver. Every method answers
 * "nothing" rather than throwing — a lookup that fails is a domain that does not point here yet.
 */
interface DnsLookup
{
    /**
     * The IPv4 and IPv6 addresses the host resolves to, following aliases.
     *
     * @return list<string>
     */
    public function addresses(string $host): array;

    /**
     * The TXT values published at the host.
     *
     * @return list<string>
     */
    public function txt(string $host): array;
}
