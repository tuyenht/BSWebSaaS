<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The SSRF choke point for every request the APP makes to an address it does not own (an image
 * link an admin pasted, a site's own public address for the SEO check). Every hop is validated:
 * http(s) only, no private/reserved IPs (literal or DNS-resolved, IPv6 with an IPv4 inside
 * included), the request pinned to the verified IP (CURLOPT_RESOLVE) so a DNS rebind cannot slip
 * past, redirects followed by hand and re-validated, the body streamed so a caller reads only
 * what it needs. Answers of any status come back; what a status means is the caller's business.
 *
 * NOT final on purpose, and the resolver is a constructor argument: tests drive the guard (a
 * hostname resolving to a private address, or a public one Http::fake then answers) without real
 * DNS — DNS is resolved before Http is called, so Http::fake alone cannot stand in for it.
 */
class SafeHttp
{
    public const int MAX_REDIRECTS = 3;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  every A/AAAA answer for a host; real DNS when null
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /**
     * GET with manual redirect handling — every hop re-passes the gate. `$sameHost` refuses a
     * redirect to any other host (http → https on the same host is fine). `$budget` caps the time of
     * all hops together, in seconds; each hop is also held to `$timeout`.
     *
     * @param  array<string, string>  $headers
     * @return array{response: Response, url: string} the final answer (body unread) and its address
     *
     * @throws SafeHttpException
     */
    public function get(string $url, array $headers = [], float $timeout = 10, float $connectTimeout = 5, bool $sameHost = false, ?float $budget = null): array
    {
        $origin = strtolower((string) parse_url($url, PHP_URL_HOST));
        $started = hrtime(true);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            // Resolve + validate ONCE, then PIN the request to that exact IP (CURLOPT_RESOLVE).
            // Without pinning, cURL re-resolves the host and a DNS-rebind (TTL 0: public on the
            // check, private on the fetch) would walk straight past the guard to 169.254.169.254.
            $conn = $this->safeConnection($url);

            $hopTimeout = $timeout;
            if ($budget !== null) {
                $left = $budget - (hrtime(true) - $started) / 1e9;
                if ($left <= 0) {
                    throw new SafeHttpException('fetch_unreachable');
                }
                $hopTimeout = min($timeout, $left);
            }

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'curl' => [CURLOPT_RESOLVE => ["{$conn['host']}:{$conn['port']}:{$conn['ip']}"]],
                ])
                    ->timeout($hopTimeout)->connectTimeout(min($connectTimeout, $hopTimeout))
                    ->withHeaders($headers)
                    ->get($url);
            } catch (ConnectionException) {
                // A timeout or a refused connection is the address's problem, not a server error.
                throw new SafeHttpException('fetch_unreachable');
            }

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                self::discard($response);
                if ($location === '') {
                    throw new SafeHttpException('redirect_invalid');
                }
                $url = self::absolutize($location, $url);
                $next = strtolower((string) parse_url($url, PHP_URL_HOST));
                if ($sameHost && $next !== $origin) {
                    throw new SafeHttpException('redirect_off_host', $next);
                }

                continue;
            }

            return ['response' => $response, 'url' => $url];
        }

        throw new SafeHttpException('too_many_redirects');
    }

    /**
     * The body up to `$cap` bytes, streamed — a lying Content-Length can never balloon memory — and
     * whether more was left unread.
     *
     * @return array{0: string, 1: bool}
     */
    public static function readUpTo(Response $response, int $cap): array
    {
        $body = $response->toPsrResponse()->getBody();

        $bytes = '';
        while (! $body->eof()) {
            $bytes .= $body->read(65536);
            if (strlen($bytes) > $cap) {
                $body->close();

                return [substr($bytes, 0, $cap), true];
            }
        }

        return [$bytes, false];
    }

    /** Drop an answer's body unread (only its status and headers were wanted). */
    public static function discard(Response $response): void
    {
        $response->toPsrResponse()->getBody()->close();
    }

    /**
     * Every A/AAAA answer for the host from real DNS.
     *
     * @return list<string>
     */
    public static function lookup(string $host): array
    {
        $ips = [];
        foreach (dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    /**
     * Every A/AAAA answer for the host. Protected (and the resolver injectable) so tests can drive
     * the guard without touching real DNS.
     *
     * @return list<string>
     */
    protected function resolveHostIps(string $host): array
    {
        return $this->resolver !== null ? ($this->resolver)($host) : self::lookup($host);
    }

    /**
     * Validate http(s) + host, resolve to a single publicly-routable IP, and return the exact
     * (host, port, ip) the request must be pinned to. EVERY resolved address is checked — a
     * hostname that resolves to even one private IP is rejected — and the pinned IP is one of
     * those verified answers. A host is either a plain IP literal or a name: number-like
     * spellings of an address are refused outright.
     *
     * @return array{host: string, port: int, ip: string}
     */
    private function safeConnection(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new SafeHttpException('url_invalid');
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            self::assertPublicIp($literal);

            return ['host' => $host, 'port' => $port, 'ip' => $literal];
        }

        // cURL reads a host whose last label is a number (0x7f.0.0.1, 2130706433, 127.1,
        // 0177.0.0.1) as an IPv4 address and connects to it, ignoring the pin below. No real
        // hostname ends that way, so it is refused here rather than left to DNS: a resolver that
        // answered for such a name (a wildcard search domain) would be vouching for loopback.
        if (preg_match('/(^|\.)(\d+|0x[0-9a-f]*)\.?$/i', $host) === 1) {
            throw new SafeHttpException('url_invalid');
        }

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            throw new SafeHttpException('host_unresolvable');
        }
        foreach ($ips as $ip) {
            self::assertPublicIp($ip);
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    private static function assertPublicIp(string $ip): void
    {
        // An IPv6 address can carry an IPv4 one inside it — IPv4-mapped/compatible
        // (::ffff:169.254.169.254, ::a.b.c.d) or NAT64 (64:ff9b::a.b.c.d) — and so smuggle a
        // private IPv4 past an IPv6-only check: validate the embedded IPv4 too.
        if (str_contains($ip, ':')) {
            $packed = @inet_pton($ip);
            if ($packed !== false && strlen($packed) === 16
                && (str_starts_with($packed, str_repeat("\x00", 10)."\xff\xff")
                    || str_starts_with($packed, str_repeat("\x00", 12))
                    || str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\x00", 8)))) {
                $embedded = inet_ntop(substr($packed, 12));
                if (is_string($embedded)) {
                    self::rejectIfNotPublic($embedded);
                }
            }
        }

        self::rejectIfNotPublic($ip);
    }

    private static function rejectIfNotPublic(string $ip): void
    {
        // GLOBAL_RANGE adds the blocks that PHP counts as neither private nor reserved yet are
        // never public: carrier-grade NAT (100.64.0.0/10 — some clouds serve instance metadata
        // there), protocol, benchmark and documentation ranges, 6to4 and Teredo.
        $publicOnly = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $publicOnly) === false) {
            throw new SafeHttpException('private_address');
        }
    }

    private static function absolutize(string $location, string $base): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = rtrim(dirname((string) ($parts['path'] ?? '/')), '/');

        return $origin.$dir.'/'.$location;
    }
}
