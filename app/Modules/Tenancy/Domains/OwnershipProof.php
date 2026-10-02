<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\Site;

/**
 * The TXT record that settles a contested domain (design.md §28): {label}.{domain} must carry a
 * value only this (domain, site) pair has. Derived from the application key and the site's public
 * id, so nothing is stored and one site's value never fits another site or another domain.
 */
final readonly class OwnershipProof
{
    public function __construct(private DnsLookup $dns) {}

    public function recordName(string $host): string
    {
        return Cast::string(config('tenancy.custom_domains.txt_label'), '_site-verification').'.'.$host;
    }

    public function recordValue(int $siteId, string $host): string
    {
        $ulid = Cast::string(Site::query()->whereKey($siteId)->value('ulid'));

        return substr(hash_hmac('sha256', 'domain-ownership|'.$ulid.'|'.$host, Cast::string(config('app.key'))), 0, 40);
    }

    public function published(int $siteId, string $host): bool
    {
        $expected = $this->recordValue($siteId, $host);

        foreach ($this->dns->txt($this->recordName($host)) as $value) {
            if (hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }
}
