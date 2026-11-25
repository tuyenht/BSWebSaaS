<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Shared\Http\SafeHttp;
use Closure;
use ErrorException;

/**
 * Keeps a site's typed-in SMTP server on the public internet. The server name and port come from a
 * site admin, and «Gửi thử» opens a connection to them, so without this a site could point the app
 * at the platform's own private services (database pool, cache, metadata endpoints) and read from
 * success, error or timeout what answers there. Checked when the settings are saved and again right
 * before each send (DNS can change in between); mail ports only.
 *
 * A name that does not resolve is refused when saving (a typo, or a name that could answer a private
 * address later); at send time the caller lets it through, so a passing DNS fault only delays mail.
 */
final class MailHostGuard
{
    /** Submission (587, 2587), implicit TLS (465), relay (25) and the providers' alternate (2525). */
    public const array PORTS = [25, 465, 587, 2525, 2587];

    public const string PRIVATE = 'private';

    public const string UNRESOLVABLE = 'unresolvable';

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver  every address the name resolves to (tests inject one) */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolve(...);
    }

    public function allowsPort(int $port): bool
    {
        return in_array($port, self::PORTS, true);
    }

    /**
     * Why a connection to this server name (or literal address) is refused, or null when it may go.
     *
     * @return self::PRIVATE|self::UNRESOLVABLE|null
     */
    public function refusal(string $host): ?string
    {
        $host = strtolower(trim(trim($host), '[]'));
        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return SafeHttp::isPublicIp($host) ? null : self::PRIVATE;
        }

        // The connection reads a name whose last label is a number (0x7f.1, 2130706433, 127.1,
        // 0177.0.0.1) as an IPv4 address and goes there, whatever DNS says. No real server name
        // ends that way.
        if (preg_match('/(^|\.)(\d+|0x[0-9a-f]*)\.?$/i', $host) === 1) {
            return self::PRIVATE;
        }

        $ips = ($this->resolver)($host);
        if ($ips === []) {
            return self::UNRESOLVABLE;
        }
        foreach ($ips as $ip) {
            if (! SafeHttp::isPublicIp($ip)) {
                return self::PRIVATE;
            }
        }

        return null;
    }

    /**
     * DNS answers plus the system resolver's: the one the connection itself uses, which also reads
     * /etc/hosts ("localhost", the container's own names). A DNS fault ("temporary server error"
     * arrives as a PHP warning) leaves the system resolver's answer and never a 500.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        try {
            $dns = SafeHttp::lookup($host);
        } catch (ErrorException) {
            $dns = [];
        }
        $system = gethostbynamel($host);

        return array_values(array_unique([...$dns, ...($system === false ? [] : $system)]));
    }
}
