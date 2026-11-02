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
     * Endings sold as one: a name under them is registered at the third label (`tencongty.com.vn`,
     * `shop.hanoi.vn`). Vietnam's whole list (the generic ones and each province's), and the
     * common ones elsewhere; any other country ending whose second label is a generic one
     * (GENERIC_SECOND_LABELS: `co.jp`, `com.br`…) counts too.
     */
    private const array TWO_LABEL_ENDINGS = [
        'com.vn', 'net.vn', 'org.vn', 'edu.vn', 'gov.vn', 'biz.vn', 'info.vn', 'name.vn', 'pro.vn',
        'health.vn', 'ac.vn', 'int.vn', 'id.vn', 'io.vn', 'ai.vn',
        'angiang.vn', 'bacgiang.vn', 'backan.vn', 'baclieu.vn', 'bacninh.vn', 'baria-vungtau.vn', 'bentre.vn',
        'binhdinh.vn', 'binhduong.vn', 'binhphuoc.vn', 'binhthuan.vn', 'camau.vn', 'cantho.vn', 'caobang.vn',
        'daklak.vn', 'daknong.vn', 'danang.vn', 'dienbien.vn', 'dongnai.vn', 'dongthap.vn', 'gialai.vn',
        'hagiang.vn', 'haiduong.vn', 'haiphong.vn', 'hanam.vn', 'hanoi.vn', 'hatinh.vn', 'haugiang.vn',
        'hoabinh.vn', 'hungyen.vn', 'khanhhoa.vn', 'kiengiang.vn', 'kontum.vn', 'laichau.vn', 'lamdong.vn',
        'langson.vn', 'laocai.vn', 'longan.vn', 'namdinh.vn', 'nghean.vn', 'ninhbinh.vn', 'ninhthuan.vn',
        'phutho.vn', 'phuyen.vn', 'quangbinh.vn', 'quangnam.vn', 'quangngai.vn', 'quangninh.vn', 'quangtri.vn',
        'soctrang.vn', 'sonla.vn', 'tayninh.vn', 'thaibinh.vn', 'thainguyen.vn', 'thanhhoa.vn',
        'thanhphohochiminh.vn', 'hochiminh.vn', 'thuathienhue.vn', 'tiengiang.vn', 'travinh.vn', 'tuyenquang.vn',
        'vinhlong.vn', 'vinhphuc.vn', 'yenbai.vn',
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk',
    ];

    /** Second labels that, under a two-letter country ending, are sold as part of the ending. */
    private const array GENERIC_SECOND_LABELS = [
        'com', 'net', 'org', 'edu', 'gov', 'co', 'ne', 'or', 'ac', 'biz', 'info', 'ltd', 'plc', 'gen', 'nom',
    ];

    /**
     * Labels kept for the platform's own services under every platform base. They always apply,
     * whatever `tenancy.reserved_labels` holds (that key only adds to them), so a stale config
     * cache or a runtime config change can never switch the check off.
     */
    public const RESERVED_LABELS = [
        // Control plane and sign-in
        'admin', 'administrator', 'api', 'app', 'auth', 'account', 'accounts', 'console',
        'dashboard', 'id', 'login', 'logout', 'oauth', 'panel', 'platform', 'portal', 'root',
        'signin', 'signup', 'sso', 'system',
        // Media and static files
        'assets', 'cdn', 'companion', 'files', 'images', 'img', 'media', 'static', 'upload', 'uploads',
        // First-party tracking (ADR-22)
        'analytics', 'collect', 'events', 'gtm', 'sgtm', 'track', 'tracking',
        // Mail and DNS (dns1/dns2 are the platform's own name servers)
        'autoconfig', 'autodiscover', 'dns', 'email', 'imap', 'mail', 'mx', 'ns', 'ns1', 'ns2',
        'ns3', 'ns4', 'dns1', 'dns2', 'mta-sts', 'pop', 'pop3', 'smtp', 'webmail', 'wpad',
        // Hosts the web server already answers itself (Caddyfile)
        'website', 'default', 'master',
        // Operations
        'ci', 'db', 'ftp', 'git', 'horizon', 'logs', 'metrics', 'monitor', 'sftp', 'ssh', 'status', 'vpn',
        // Money and support
        'billing', 'docs', 'help', 'invoice', 'pay', 'payment', 'payments', 'support',
    ];

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
     * be verified from birth: exactly one plain ASCII label under a platform base (`{slug}.{base}`),
     * never `www`, never punycode (`xn--`, which can render as a look-alike of a reserved name),
     * never a reserved label (`tenancy.reserved_labels`) and never one of the platform's own hosts
     * (console, target, media…). Anything else — a customer's domain above all — must be proven
     * through the normal verification flow.
     */
    public static function isPlatformSubdomain(string $host): bool
    {
        $label = strstr($host, '.', true);

        if ($label === false || $label === '' || $label === 'www' || str_starts_with($label, 'xn--')
            || in_array($host, self::serviceHosts(), true) || self::reservedLabel($host) !== null) {
            return false;
        }

        return in_array(substr($host, strlen($label) + 1), self::bases(), true);
    }

    /**
     * The label of a `{label}.{base}` name when that label is kept for the platform's own services
     * (`tenancy.reserved_labels`), else null — so a refusal can name it.
     */
    public static function reservedLabel(string $host): ?string
    {
        $label = strstr($host, '.', true);

        if ($label === false || ! in_array(substr($host, strlen($label) + 1), self::bases(), true)) {
            return null;
        }

        $added = config('tenancy.reserved_labels');

        return in_array($label, self::RESERVED_LABELS, true) || (is_array($added) && in_array($label, $added, true))
            ? $label
            : null;
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
     * The name a customer registered — the part the DNS rows of a name are written under, so the
     * pointing table can say `@` for it and `shop` / `www.shop` for the names below it. Knows the
     * two-label endings people buy most (`com.vn`, `co.uk`…); any other ending is one label.
     */
    public static function registrable(string $host): string
    {
        $labels = explode('.', $host);
        $count = count($labels);

        if ($count <= 2) {
            return $host;
        }

        $second = $labels[$count - 2];
        $top = $labels[$count - 1];
        $twoLabel = in_array($second.'.'.$top, self::TWO_LABEL_ENDINGS, true)
            || (strlen($top) === 2 && in_array($second, self::GENERIC_SECOND_LABELS, true));
        $keep = $twoLabel ? 3 : 2;

        return implode('.', array_slice($labels, -$keep));
    }

    /** A stored hostname as people read it: `bảo-sơn.vn`, not its punycode. */
    public static function display(string $host): string
    {
        if (! str_contains($host, 'xn--')) {
            return $host;
        }

        $unicode = idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($unicode) && $unicode !== '' ? $unicode : $host;
    }

    /**
     * @return list<string>
     */
    private static function platformHosts(): array
    {
        return array_values(array_unique([...self::serviceHosts(), ...self::bases()]));
    }

    /**
     * The platform base domains (`tenancy.base_domains` plus `tenancy.primary_base`).
     *
     * @return list<string>
     */
    private static function bases(): array
    {
        return self::hosts([
            ...(is_array($bases = config('tenancy.base_domains')) ? $bases : []),
            config('tenancy.primary_base'),
        ]);
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
