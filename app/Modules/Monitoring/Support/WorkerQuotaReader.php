<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Support;

use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads how many Workers requests the whole Cloudflare account has made on one UTC day, from the
 * Cloudflare GraphQL analytics API (all scripts summed). Returns null whenever the number cannot
 * be trusted — a transport error, an error answer, an unexpected shape — and never throws. The
 * token and the account id stay out of every log line.
 */
final class WorkerQuotaReader
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/graphql';

    private const QUERY = 'query($acct: String!, $d: Date!) { viewer { accounts(filter: {accountTag: $acct}) { workersInvocationsAdaptive(limit: 100, filter: {date_geq: $d, date_leq: $d}) { sum { requests } } } } }';

    public static function token(): string
    {
        return Cast::string(config('services.cloudflare.api_token'));
    }

    /** CLOUDFLARE_ACCOUNT_ID when set, else the first label of the R2 endpoint (https://<32 hex>.r2.cloudflarestorage.com). */
    public static function accountId(): string
    {
        $explicit = trim(Cast::string(config('services.cloudflare.account_id')));
        if ($explicit !== '') {
            return $explicit;
        }

        $endpoint = Cast::string(config('filesystems.disks.r2.endpoint'));

        return preg_match('#^https://([0-9a-f]{32})\.r2\.cloudflarestorage\.com/?$#i', trim($endpoint), $m) === 1 ? $m[1] : '';
    }

    public static function configured(): bool
    {
        return self::token() !== '' && self::accountId() !== '';
    }

    /** @param  string  $date  UTC date, Y-m-d */
    public function requestsOn(string $date): ?int
    {
        try {
            $response = Http::withToken(self::token())
                ->timeout(15)
                ->acceptJson()
                ->post(self::ENDPOINT, [
                    'query' => self::QUERY,
                    'variables' => ['acct' => self::accountId(), 'd' => $date],
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json) || ! empty($json['errors'])) {
            return null;
        }

        $data = $json['data'] ?? null;
        $viewer = is_array($data) ? ($data['viewer'] ?? null) : null;
        $accounts = is_array($viewer) ? ($viewer['accounts'] ?? null) : null;
        $account = is_array($accounts) ? ($accounts[0] ?? null) : null;
        $rows = is_array($account) ? ($account['workersInvocationsAdaptive'] ?? null) : null;
        if (! is_array($rows)) {
            return null;
        }

        $total = 0;
        foreach ($rows as $row) {
            $sum = is_array($row) ? ($row['sum'] ?? null) : null;
            $requests = is_array($sum) ? ($sum['requests'] ?? null) : null;
            if (! is_int($requests) && ! (is_string($requests) && ctype_digit($requests))) {
                return null;
            }
            $total += Cast::int($requests);
        }

        return $total;
    }
}
