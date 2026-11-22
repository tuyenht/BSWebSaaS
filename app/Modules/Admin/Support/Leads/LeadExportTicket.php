<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Leads;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The one-time pass between «Tải danh sách về máy» (where the member proved it is them) and the
 * download: a random token that lives TTL seconds and opens one download only, for the member it
 * was issued to, on the website it was issued on, of the filter the list showed then. The address
 * carries the token alone; the filter stays on the server, so the address can be neither widened
 * nor replayed. Only a hash of the token is the cache key.
 */
final class LeadExportTicket
{
    public const int TTL = 60;

    public const string PATTERN = '[A-Za-z0-9]{40}';

    /**
     * @param  array{tab: string, search: string, forms: list<string>, when: string, from: string, to: string}  $filter
     */
    public static function issue(User $user, int $siteId, array $filter): string
    {
        $token = Str::random(40);
        Cache::put(self::key($token), ['user' => Cast::int($user->getKey()), 'site' => $siteId, 'filter' => $filter], self::TTL);

        return $token;
    }

    /**
     * The filter the token was issued for, once: a token that is unknown, expired, already used,
     * another member's or another website's opens nothing (and is spent all the same).
     *
     * @return array{tab: string, search: string, forms: list<string>, when: string, from: string, to: string}|null
     */
    public static function take(string $token, User $user, int $siteId): ?array
    {
        if (preg_match('/^'.self::PATTERN.'$/', $token) !== 1) {
            return null;
        }
        $key = self::key($token);
        $ticket = Cache::get($key);
        // the forget decides who used it: of two requests with the same token, one deletes it
        if (! is_array($ticket) || ! Cache::forget($key)) {
            return null;
        }
        if (Cast::int($ticket['user'] ?? null) !== Cast::int($user->getKey()) || Cast::int($ticket['site'] ?? null) !== $siteId) {
            return null;
        }
        $filter = Cast::arr($ticket['filter'] ?? null);

        return [
            'tab' => Cast::string($filter['tab'] ?? 'all'),
            'search' => Cast::string($filter['search'] ?? ''),
            'forms' => array_values(array_map(strval(...), array_filter(Cast::arr($filter['forms'] ?? []), 'is_string'))),
            'when' => Cast::string($filter['when'] ?? ''),
            'from' => Cast::string($filter['from'] ?? ''),
            'to' => Cast::string($filter['to'] ?? ''),
        ];
    }

    private static function key(string $token): string
    {
        return 'lead-export:'.hash('sha256', $token);
    }
}
