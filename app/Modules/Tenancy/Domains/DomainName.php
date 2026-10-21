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
     * A name the platform may hand out as a website's own subdomain: exactly one label under a
     * platform base domain (`{slug}.{base}`, as `primary_base` builds them), and not one of the
     * platform's own hosts that may sit there too (console, pointing target, sign-in, media).
     * Anything else belongs to someone else — a customer's domain is theirs to prove, never ours
     * to stamp as verified. One label only, so no website is created under another's name; never
     * `www`, the platform's own address on that base.
     */
    public static function isPlatformSubdomain(string $host): bool
    {
        if (in_array($host, self::infrastructureHosts(), true)) {
            return false;
        }

        foreach (self::baseDomains() as $base) {
            $label = str_ends_with($host, '.'.$base) ? substr($host, 0, -strlen('.'.$base)) : '';

            if ($label !== '' && $label !== 'www' && ! str_contains($label, '.')) {
                return true;
            }
        }

        return false;
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
        return array_values(array_unique([...self::infrastructureHosts(), ...self::baseDomains()]));
    }

    /**
     * Every platform base domain, with `primary_base` among them.
     *
     * @return list<string>
     */
    private static function baseDomains(): array
    {
        return self::hosts([
            ...(is_array($bases = config('tenancy.base_domains')) ? $bases : []),
            config('tenancy.primary_base'),
        ]);
    }

    /**
     * The platform's own hosts: console, pointing target, and the hosts behind sign-in and media.
     *
     * @return list<string>
     */
    private static function infrastructureHosts(): array
    {
        $candidates = [
            config('tenancy.platform_host'),
            config('tenancy.custom_domains.target'),
        ];

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
