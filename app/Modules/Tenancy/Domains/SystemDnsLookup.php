<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use Throwable;

/**
 * DnsLookup over the system resolver. dns_get_record raises a warning (an exception under
 * Laravel) when a name does not exist or the resolver times out — both mean "no answer".
 */
final class SystemDnsLookup implements DnsLookup
{
    #[\Override]
    public function addresses(string $host): array
    {
        $addresses = [];

        foreach ($this->records($host, DNS_A | DNS_AAAA) as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            $packed = is_string($address) ? inet_pton($address) : false;

            // Through inet_pton/ntop so one IPv6 address always compares equal to itself.
            if ($packed !== false && ($normal = inet_ntop($packed)) !== false) {
                $addresses[$normal] = true;
            }
        }

        return array_keys($addresses);
    }

    #[\Override]
    public function txt(string $host): array
    {
        $values = [];

        foreach ($this->records($host, DNS_TXT) as $record) {
            if (is_string($record['txt'] ?? null)) {
                $values[] = trim($record['txt']);
            }
        }

        return $values;
    }

    /**
     * @return list<array<mixed>>
     */
    private function records(string $host, int $type): array
    {
        try {
            $records = dns_get_record($host, $type);
        } catch (Throwable) {
            return [];
        }

        return is_array($records) ? $records : [];
    }
}
