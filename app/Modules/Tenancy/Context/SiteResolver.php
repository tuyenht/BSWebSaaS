<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * The ONE place a hostname becomes a site (design.md §5 layer #1, §28). Every caller that used to
 * read `domains` by hostname — the tenancy middleware, the Livewire endpoint, the OAuth callback,
 * the certificate gate, operator commands — asks here, so they all share one rule:
 *
 *   only a VERIFIED domain resolves. A platform subdomain is verified from birth; a customer's own
 *   domain once it points at the platform. A name that is merely waiting on a site resolves to
 *   nothing, exactly like a name nobody attached.
 *
 * The answer is cached in the shared cache (never in the worker: Octane keeps workers alive and
 * one worker could not invalidate another). A hit lives 5 minutes; a miss 60 seconds, so a flood
 * of unknown hosts costs one query per host per minute. Whoever writes `domains` calls forget()
 * for the hosts it touched — the TTL is only the backstop. A host that is not even shaped like a
 * hostname is refused without touching the cache or the database, so junk Host headers cannot
 * fill the cache. If the cache is down the database answers: resolution must not depend on it.
 */
final readonly class SiteResolver
{
    /** Bump when the SHAPE of the cached value changes — the old value outlives the deploy. */
    private const string KEY = 'tenancy:host:v1:';

    private const int HIT_TTL = 300;

    private const int MISS_TTL = 60;

    public function __construct(private Cache $cache) {}

    public function resolve(string $host): ?ResolvedHost
    {
        $host = self::normalize($host);

        if ($host === null) {
            return null;
        }

        $cached = $this->read($host);

        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        $domain = Domain::query()
            ->select(['site_id', 'kind', 'ssl_status'])
            ->where('hostname', $host)
            ->whereNotNull('verified_at')
            ->first();

        $resolved = $domain === null ? null : new ResolvedHost(
            siteId: $domain->site_id,
            isPlatformSubdomain: $domain->kind === 'subdomain',
            httpsOn: $domain->ssl_status !== null,
        );

        $this->write($host, $resolved);

        return $resolved;
    }

    /** Drop what is remembered about these hosts — call after any write to `domains`. */
    public function forget(string ...$hosts): void
    {
        foreach ($hosts as $host) {
            $host = self::normalize($host);

            if ($host === null) {
                continue;
            }

            try {
                $this->cache->forget(self::KEY.$host);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Lower-case, without a trailing dot; null when it cannot be a public hostname (empty, too
     * long, an IP, a single label, characters outside letters/digits/hyphen). Punycode labels
     * (xn--…) pass, in the name and in the top-level label.
     */
    public static function normalize(string $host): ?string
    {
        $host = rtrim(strtolower(trim($host)), '.');

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    /** A resolved host, false for a remembered miss, null when nothing is remembered. */
    private function read(string $host): ResolvedHost|false|null
    {
        try {
            $value = $this->cache->get(self::KEY.$host);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $siteId = Cast::int($value['site_id'] ?? null);

        if ($siteId === 0) {
            return false;
        }

        return new ResolvedHost(
            siteId: $siteId,
            isPlatformSubdomain: ($value['subdomain'] ?? null) === true,
            httpsOn: ($value['https'] ?? null) === true,
        );
    }

    private function write(string $host, ?ResolvedHost $resolved): void
    {
        try {
            if ($resolved === null) {
                $this->cache->put(self::KEY.$host, ['site_id' => 0], self::MISS_TTL);

                return;
            }

            $this->cache->put(self::KEY.$host, [
                'site_id' => $resolved->siteId,
                'subdomain' => $resolved->isPlatformSubdomain,
                'https' => $resolved->httpsOn,
            ], self::HIT_TTL);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
