<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use App\Modules\Shared\Contracts\EdgeCache;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteDirectory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Drops every edge-cached page of one site after a change to what it shows. Dispatched after the
 * write commits (EdgeCachePurges). One waiting job per site: more writes while it waits add nothing,
 * and a write made while it runs queues the next one (the lock goes as processing starts), so the
 * purge always follows the last commit.
 *
 * Purge by host name; a refusal is pursued as RetriesHostPurge says (the home pages by URL at once,
 * the host purge again later, an error logged after the last try). `$alsoHosts` names hosts that no
 * longer belong to the site (a removed or renamed domain), purged together with its own: what the
 * edge kept for them must go too. Such a dispatch is never dropped as a duplicate of a waiting plain
 * one: its unique id carries a hash of those names.
 *
 * Only names inside the platform's own zone go (`tenancy.base_domains` and their subdomains): a
 * purge call naming a host of another zone is refused whole by Cloudflare. A customer's own domain
 * is not purged; its pages are kept only `storefront.edge_cache.unpurged_s_maxage` (EdgeCacheHeaders)
 * and age out (Cloudflare for SaaS would cover it later).
 */
final class PurgeSiteEdgeCache implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RetriesHostPurge;

    public int $uniqueFor = 600;

    /** @param list<string> $alsoHosts hosts the site no longer has, purged with its own */
    public function __construct(public readonly int $siteId, public readonly array $alsoHosts = []) {}

    public function uniqueId(): string
    {
        if ($this->alsoHosts === []) {
            return (string) $this->siteId;
        }

        $names = array_values(array_unique(array_map(static fn (string $host): string => strtolower(rtrim($host, '.')), $this->alsoHosts)));
        sort($names);

        return $this->siteId.':'.hash('xxh128', implode("\n", $names));
    }

    public function handle(EdgeCache $edge, SiteDirectory $sites): void
    {
        $hosts = self::inZone(array_values(array_unique([...$sites->hostsOf($this->siteId), ...$this->alsoHosts])));

        $this->purgeOrRetry($edge, $hosts, ['site_id' => $this->siteId]);
    }

    /**
     * The hosts that are a platform base domain or one of its subdomains.
     *
     * @param  list<string>  $hosts
     * @return list<string>
     */
    public static function inZone(array $hosts): array
    {
        $bases = array_map(static fn (string $base): string => strtolower(trim($base, ' .')), Cast::strings(config('tenancy.base_domains')));
        $bases = array_filter($bases, static fn (string $base): bool => $base !== '');

        return array_values(array_filter($hosts, static function (string $host) use ($bases): bool {
            $host = strtolower(rtrim($host, '.'));
            foreach ($bases as $base) {
                if ($host === $base || str_ends_with($host, '.'.$base)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
