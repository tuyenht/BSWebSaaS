<?php

declare(strict_types=1);

namespace App\Modules\Storefront\EdgeCache;

use App\Modules\Shared\Contracts\EdgeCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare's purge API for one zone (POST /zones/{zone}/purge_cache). The token needs only
 * "Zone → Cache Purge". At most 30 host names or URLs go in one call (the limit below Enterprise).
 * A refusal (rate limit, plan, bad token) or a network fault answers false and leaves a warning in
 * the log; nothing is thrown, so a failed purge never fails the write that caused it.
 */
final readonly class CloudflareEdgeCache implements EdgeCache
{
    private const string API = 'https://api.cloudflare.com/client/v4/zones/';

    private const int PER_CALL = 30;

    public function __construct(private string $zoneId, private string $token) {}

    public function purgeHosts(array $hosts): bool
    {
        return $this->purge('hosts', $hosts);
    }

    public function purgeUrls(array $urls): bool
    {
        return $this->purge('files', $urls);
    }

    /**
     * @param  'hosts'|'files'  $kind
     * @param  list<string>  $items
     */
    private function purge(string $kind, array $items): bool
    {
        foreach (array_chunk(array_values(array_unique($items)), self::PER_CALL) as $chunk) {
            try {
                $response = Http::withToken($this->token)
                    ->acceptJson()
                    ->timeout(10)
                    ->post(self::API.rawurlencode($this->zoneId).'/purge_cache', [$kind => $chunk]);
            } catch (Throwable $e) {
                Log::warning('Edge cache purge could not reach Cloudflare.', ['kind' => $kind, 'error' => $e->getMessage()]);

                return false;
            }

            if (! $response->successful() || $response->json('success') !== true) {
                Log::warning('Cloudflare refused an edge cache purge.', ['kind' => $kind, 'status' => $response->status(), 'errors' => $response->json('errors')]);

                return false;
            }
        }

        return true;
    }
}
