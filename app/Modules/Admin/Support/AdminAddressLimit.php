<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Where a website's admin may be opened from (Cài đặt › Bảo mật & Nâng cao, «Giới hạn nơi được vào
 * trang quản trị»): while it is on, every request to the website's admin (/admin…, its sign-in
 * pages and Livewire updates) must come from one of the listed network addresses, the owner's own
 * included; anything else gets the plain «Không vào được trang quản trị từ nơi này» page
 * (RestrictAdminAddress). Stored in site_settings group `admin_access`, key `ip_limit`:
 *
 *   {"on": bool, "entries": [{"address": "203.0.113.0/24", "note": "Văn phòng Đà Nẵng"}, …]}
 *
 * An address is one IPv4 or IPv6 address, or a range in CIDR form (/1–32, /1–128), kept in one
 * written form (normalize): an IPv4 address written as IPv6 (::ffff:203.0.113.24) is kept as the
 * IPv4 address it is, a range keeps only its network part. A range of /0 (0.0.0.0/0, ::/0) would let
 * every address in and is refused. The visitor's address is the request's own ($request->ip()):
 * Caddy resolves it from Cloudflare and the app trusts only the private proxy ranges
 * (bootstrap/app.php), so a header a visitor writes cannot choose it.
 *
 * Read on every admin request without a query of its own: the addresses are kept in the shared
 * cache per website (never in the worker: one Octane worker could not drop another's copy). A
 * write to the setting drops that copy once committed (AdminServiceProvider); the short TTL is only
 * the backstop for a read that raced the write. If the cache is down the database answers. Bound
 * scoped, its memo is keyed by site as well.
 */
final class AdminAddressLimit
{
    public const string GROUP = 'admin_access';

    public const string KEY = 'ip_limit';

    /** Most addresses one website may list. */
    public const int MAX_ENTRIES = 50;

    /** Longest note kept per address. */
    public const int MAX_NOTE = 100;

    /** Bump when the SHAPE of the cached value changes: the old value outlives the deploy. */
    private const string CACHE_KEY = 'admin-ip-limit:v1:';

    private const int TTL = 60;

    /** The first twelve bytes of an IPv4 address written as IPv6 (::ffff:a.b.c.d). */
    private const string MAPPED = "\0\0\0\0\0\0\0\0\0\0\xFF\xFF";

    /** @var array<int, list<string>|null> site id → the addresses its admin is limited to, null when it is not */
    private array $memo = [];

    public function __construct(
        private readonly CurrentSite $current,
        private readonly Cache $cache,
    ) {}

    /**
     * An address as it is written and kept, or why it cannot be one: `empty`, `invalid` (not an
     * address), `prefix` (the part after / is not 1–32 or 1–128) or `everywhere` (a /0 range).
     *
     * @return array{ok: true, address: string}|array{ok: false, error: 'empty'|'invalid'|'prefix'|'everywhere'}
     */
    public static function normalize(string $input): array
    {
        $text = trim($input);
        if ($text === '') {
            return ['ok' => false, 'error' => 'empty'];
        }
        $address = $text;
        $bits = null;
        if (str_contains($text, '/')) {
            [$address, $prefix] = explode('/', $text, 2);
            if (preg_match('/^(0|[1-9]\d{0,2})$/', $prefix) !== 1) {
                return ['ok' => false, 'error' => filter_var($address, FILTER_VALIDATE_IP) !== false ? 'prefix' : 'invalid'];
            }
            $bits = (int) $prefix;
        }
        $packed = filter_var($address, FILTER_VALIDATE_IP) !== false ? @inet_pton($address) : false;
        if (! is_string($packed)) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        // an IPv4 host written as IPv6 is that IPv4 host (and a mapped range of /96 or more an IPv4 range)
        if (strlen($packed) === 16 && str_starts_with($packed, self::MAPPED) && ($bits === null || $bits >= 96)) {
            $packed = substr($packed, 12);
            $bits = $bits === null ? null : $bits - 96;
        }
        $max = strlen($packed) * 8;
        if ($bits !== null && $bits > $max) {
            return ['ok' => false, 'error' => 'prefix'];
        }
        if ($bits === 0) {
            return ['ok' => false, 'error' => 'everywhere'];
        }
        if ($bits === null || $bits === $max) {
            return ['ok' => true, 'address' => Cast::string(inet_ntop($packed))];
        }

        // a range keeps only its network part: 203.0.113.24/24 is 203.0.113.0/24
        $network = '';
        foreach (str_split($packed) as $i => $byte) {
            $keep = max(0, min(8, $bits - 8 * $i));
            $network .= chr(ord($byte) & ((0xFF << (8 - $keep)) & 0xFF));
        }

        return ['ok' => true, 'address' => inet_ntop($network).'/'.$bits];
    }

    /** A visitor's address in the form the list is kept in (an IPv4 host written as IPv6 is that IPv4 host), or null. */
    public static function clientForm(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $result = self::normalize($ip);

        return $result['ok'] ? $result['address'] : null;
    }

    /**
     * Whether $ip is one of $addresses (each kept by normalize()).
     *
     * @param  list<string>  $addresses
     */
    public static function covers(array $addresses, ?string $ip): bool
    {
        $client = self::clientForm($ip);

        return $client !== null && $addresses !== [] && IpUtils::checkIp($client, $addresses);
    }

    /**
     * The stored value in its one shape: unknown keys dropped, each address as normalize() keeps it
     * (one that is not an address any more is dropped), each note a trimmed string, at most
     * MAX_ENTRIES of them.
     *
     * @return array{on: bool, entries: list<array{address: string, note: string}>}
     */
    public static function shape(mixed $stored): array
    {
        $stored = Cast::arr($stored);
        $entries = [];
        foreach (Cast::arr($stored['entries'] ?? null) as $entry) {
            $entry = Cast::arr($entry);
            $address = self::normalize(Cast::string($entry['address'] ?? null));
            if (! $address['ok']) {
                continue;
            }
            $entries[] = ['address' => $address['address'], 'note' => mb_substr(trim(Cast::string($entry['note'] ?? null)), 0, self::MAX_NOTE)];
            if (count($entries) === self::MAX_ENTRIES) {
                break;
            }
        }

        return ['on' => ($stored['on'] ?? false) === true, 'entries' => $entries];
    }

    /**
     * The addresses the current website's admin is limited to, or null when it is not limited (or
     * there is no website). A limit that is on with no address left lets no one in: the way back is
     * support, never an open admin.
     *
     * @return list<string>|null
     */
    public function addresses(): ?array
    {
        if (! $this->current->hasId()) {
            return null;
        }
        $siteId = $this->current->id();
        if (! array_key_exists($siteId, $this->memo)) {
            $this->memo[$siteId] = $this->load($siteId);
        }

        return $this->memo[$siteId];
    }

    /** Whether a request from $ip may open the current website's admin. */
    public function allows(?string $ip): bool
    {
        $addresses = $this->addresses();

        return $addresses === null || self::covers($addresses, $ip);
    }

    /** Drop the site's shared copy once the write is committed (at once outside a transaction). */
    public function forget(int $siteId): void
    {
        $this->memo = [];
        DB::afterCommit(function () use ($siteId): void {
            try {
                $this->cache->forget(self::CACHE_KEY.$siteId);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** @return list<string>|null */
    private function load(int $siteId): ?array
    {
        $key = self::CACHE_KEY.$siteId;
        try {
            $cached = $this->cache->get($key);
            if (is_array($cached) && is_bool($cached['on'] ?? null) && is_array($cached['addresses'] ?? null)) {
                return $cached['on'] ? array_values(array_filter($cached['addresses'], is_string(...))) : null;
            }
        } catch (Throwable $e) {
            report($e);
        }

        $shape = self::shape(SiteSetting::query()->where('group_name', self::GROUP)->where('key', self::KEY)->first(['value'])?->value);
        $addresses = array_column($shape['entries'], 'address');
        try {
            $this->cache->put($key, ['on' => $shape['on'], 'addresses' => $addresses], self::TTL);
        } catch (Throwable $e) {
            report($e);
        }

        return $shape['on'] ? $addresses : null;
    }
}
