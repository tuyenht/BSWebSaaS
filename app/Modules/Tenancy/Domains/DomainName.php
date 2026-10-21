<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;

/**
 * Turns what a person typed into the hostname the platform stores, and knows which names a
 * customer may never attach (design.md §28).
 */
final class DomainName
{
    /** Single-label names reserved for testing (RFC 6761), the only ones allowed as a base. */
    private const array SPECIAL_USE_BASES = ['test', 'localhost', 'example', 'invalid'];

    /**
     * The stored form: lower-case ASCII (punycode for names with diacritics), no scheme, port,
     * path or trailing dot. People paste whole addresses, so `https://Công-Ty.vn/lien-he` is fine.
     *
     * @throws DomainChangeRejectedException invalid
     */
    public static function parse(string $input): string
    {
        $name = trim($input);
        $name = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $name);
        $name = (string) preg_replace('#[/?\#].*$#', '', $name);
        $name = (string) preg_replace('#:\d+$#', '', $name);

        if ($name === '' || str_contains($name, '@')) {
            throw new DomainChangeRejectedException('invalid');
        }

        $ascii = idn_to_ascii(mb_strtolower($name), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        $host = $ascii === false ? null : SiteResolver::normalize($ascii);

        if ($host === null) {
            throw new DomainChangeRejectedException('invalid');
        }

        return $host;
    }

    /**
     * Names that belong to the platform itself: its console host, the host customers point at,
     * every platform base domain and anything under them, and the hosts that serve sign-in
     * callbacks and media. A customer attaching one of these would take over platform traffic.
     */
    public static function isReserved(string $host): bool
    {
        foreach (self::platformHosts() as $reserved) {
            if ($host === $reserved || str_ends_with($host, '.'.$reserved)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A name the platform hands out to a new site, so it is the platform's by construction and may
     * be verified from birth: exactly one label under a platform base (`{slug}.{base}`), never `www`
     * and never one of the platform's own hosts (console, target, media…). Anything else — a
     * customer's domain above all — must be proven through the normal verification flow.
     */
    public static function isPlatformSubdomain(string $host): bool
    {
        $label = strstr($host, '.', true);

        if ($label === false || $label === '' || $label === 'www' || in_array($host, self::serviceHosts(), true)) {
            return false;
        }

        return in_array(substr($host, strlen($label) + 1), self::bases(), true);
    }

    /**
     * The other spelling of a name: `www.example.com` for `example.com`, `www.shop.example.com`
     * for `shop.example.com`, and the reverse.
     */
    public static function sibling(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
    }

    /**
     * What a name and its `www.` spelling share — the spelling without `www.`, which runs by
     * default; the pair is the unit a plan's domain limit counts.
     */
    public static function pairKey(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @return list<string>
     */
    private static function platformHosts(): array
    {
        return array_values(array_unique([...self::serviceHosts(), ...self::bases()]));
    }

    /**
     * The platform base domains (`tenancy.base_domains` plus `tenancy.primary_base`). A base of a
     * single label is kept only when it is a special-use name that never exists on the internet
     * (RFC 6761: `test` for dev and CI fixtures); a public one such as `net` would make every
     * `customer.net` a platform subdomain.
     *
     * @return list<string>
     */
    private static function bases(): array
    {
        $candidates = [
            ...(is_array($bases = config('tenancy.base_domains')) ? $bases : []),
            config('tenancy.primary_base'),
        ];

        $special = [];

        foreach ($candidates as $candidate) {
            $name = is_string($candidate) ? rtrim(strtolower(trim($candidate)), '.') : '';

            if (in_array($name, self::SPECIAL_USE_BASES, true)) {
                $special[] = $name;
            }
        }

        return array_values(array_unique([...self::hosts($candidates), ...$special]));
    }

    /**
     * The hosts the platform itself serves on: its console, the host customers point at, the
     * app URL, the sign-in callback and media.
     *
     * @return list<string>
     */
    private static function serviceHosts(): array
    {
        $candidates = [config('tenancy.platform_host'), config('tenancy.custom_domains.target')];

        foreach ([config('app.url'), config('admin.oauth_callback_url'), config('media.cloudflare.base'), config('media.imgproxy.base')] as $url) {
            $candidates[] = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        }

        return self::hosts($candidates);
    }

    /**
     * @param  array<mixed>  $candidates
     * @return list<string>
     */
    private static function hosts(array $candidates): array
    {
        $hosts = [];

        foreach ($candidates as $candidate) {
            $host = is_string($candidate) ? SiteResolver::normalize($candidate) : null;

            if ($host !== null) {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }
}
