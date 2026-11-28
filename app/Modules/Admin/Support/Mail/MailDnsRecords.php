<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Tenancy\Domains\DnsLookup;

/**
 * Reads a sending domain's SPF, DKIM and DMARC records from public DNS (TXT only, through the
 * DnsLookup seam; an alias is followed by the resolver) and says, for each, what was found:
 *
 * - `ok`: the record is there (SPF: and lets the service send);
 * - `missing`: no record where the service puts it;
 * - `not_allowed`: an SPF record is there but does not name the service;
 * - `multiple`: more than one SPF (or DMARC) record at one name, which makes receivers ignore them;
 * - `unchecked`, with `why`: nothing could be concluded — `public_mailbox` (Gmail, Outlook…),
 *   `provider_managed` (the service proves SPF on a name of its own), `unknown_selector` (the
 *   service names its DKIM key per domain), `unknown_server` (an «Email khác» server not known).
 *
 * `host` is the name looked at (or the one to add to). The domain must come through
 * MailDnsProviders::domain(); the names looked up are built from it and fixed labels only.
 */
final readonly class MailDnsRecords
{
    public const array FAILING = ['missing', 'not_allowed', 'multiple'];

    /**
     * Labels that, right under a two-letter country ending, make a public suffix rather than a
     * registrable name (com.vn, id.vn, co.uk, gov.uk, co.za, com.mx…), so the organisational domain
     * keeps three labels there. A rule, not the whole Public Suffix List: a suffix it misses could
     * have its operator's own DMARC record taken for the website's, so it errs towards suffixes.
     */
    private const array SECOND_LEVEL_LABELS = [
        'ac', 'biz', 'co', 'com', 'edu', 'gen', 'go', 'gob', 'gov', 'health', 'id', 'in', 'info', 'int', 'io', 'ltd',
        'mil', 'name', 'ne', 'net', 'nic', 'or', 'org', 'plc', 'pro', 'sch',
    ];

    public function __construct(private DnsLookup $dns) {}

    /**
     * @return array{spf: array{code: string, why?: string, host?: string}, dkim: array{code: string, why?: string, host?: string}, dmarc: array{code: string, why?: string, host?: string}}
     */
    public function check(string $domain, string $provider): array
    {
        if (MailDnsProviders::isPublicMailbox($domain)) {
            $row = ['code' => 'unchecked', 'why' => 'public_mailbox'];

            return ['spf' => $row, 'dkim' => $row, 'dmarc' => $row];
        }
        $known = MailDnsProviders::TABLE[$provider] ?? MailDnsProviders::TABLE['smtp'];

        return [
            'spf' => $this->spf($domain, $provider, $known),
            'dkim' => $this->dkim($domain, $provider, $known['dkim']),
            'dmarc' => $this->dmarc($domain),
        ];
    }

    /**
     * @param  array{spf: array{host: string, include: string|null}|null, spf_needed: bool, dkim: list<string>}  $known
     * @return array{code: string, why?: string, host?: string}
     */
    private function spf(string $domain, string $provider, array $known): array
    {
        $want = $known['spf'];
        if ($want === null) {
            return ['code' => 'unchecked', 'why' => $provider === 'smtp' ? 'unknown_server' : 'provider_managed'];
        }
        $host = $want['host'] === '' ? $domain : $want['host'].'.'.$domain;
        $records = array_values(array_filter($this->dns->txt($host), self::isSpf(...)));
        $unchecked = ['code' => 'unchecked', 'why' => 'provider_managed', 'host' => $host];

        return match (true) {
            $records === [] => $known['spf_needed'] ? ['code' => 'missing', 'host' => $host] : $unchecked,
            count($records) > 1 => ['code' => 'multiple', 'host' => $host],
            $want['include'] === null || self::allows($records[0], $want['include']) => ['code' => 'ok', 'host' => $host],
            $known['spf_needed'] => ['code' => 'not_allowed', 'host' => $host],
            default => $unchecked,
        };
    }

    /**
     * @param  list<string>  $selectors
     * @return array{code: string, why?: string, host?: string}
     */
    private function dkim(string $domain, string $provider, array $selectors): array
    {
        if ($selectors === []) {
            return ['code' => 'unchecked', 'why' => $provider === 'smtp' ? 'unknown_server' : 'unknown_selector'];
        }
        foreach ($selectors as $selector) {
            $host = $selector.'._domainkey.'.$domain;
            foreach ($this->dns->txt($host) as $value) {
                if (self::isDkimKey($value)) {
                    return ['code' => 'ok', 'host' => $host];
                }
            }
        }

        return ['code' => 'missing', 'host' => $selectors[0].'._domainkey.'.$domain];
    }

    /** @return array{code: string, why?: string, host?: string} */
    private function dmarc(string $domain): array
    {
        // the domain's own policy, else its organisational domain's (a subdomain takes that one)
        foreach (array_unique([$domain, self::organisational($domain)]) as $name) {
            $host = '_dmarc.'.$name;
            $records = array_filter($this->dns->txt($host), self::isDmarc(...));
            if (count($records) === 1) {
                return ['code' => 'ok', 'host' => $host];
            }
            if ($records !== []) {
                return ['code' => 'multiple', 'host' => $host];
            }
        }

        return ['code' => 'missing', 'host' => '_dmarc.'.$domain];
    }

    /** An SPF record: `v=spf1` as its first term (RFC 7208 §4.5). */
    /** The registered domain $domain sits under: its last two labels, three under a public second level. */
    public static function organisational(string $domain): string
    {
        $labels = explode('.', $domain);
        $count = count($labels);
        $suffix = $count >= 3 && strlen($labels[$count - 1]) === 2 && in_array($labels[$count - 2], self::SECOND_LEVEL_LABELS, true);
        $keep = $suffix ? 3 : 2;

        return implode('.', array_slice($labels, -min($keep, count($labels))));
    }

    public static function isSpf(string $value): bool
    {
        return preg_match('/^v=spf1(\s|$)/i', trim($value)) === 1;
    }

    /** Whether the SPF record names $include, as an `include:` (any qualifier) or a `redirect=`. */
    public static function allows(string $record, string $include): bool
    {
        foreach (preg_split('/\s+/', trim($record)) ?: [] as $term) {
            if (preg_match('/^(?:[+~?-]?include:|redirect=)(\S+)$/i', $term, $m) === 1
                && strtolower(rtrim($m[1], '.')) === $include) {
                return true;
            }
        }

        return false;
    }

    /** A DKIM key record: a non-empty `p=` tag (an empty one is a revoked key, RFC 6376 §3.6.1). */
    public static function isDkimKey(string $value): bool
    {
        foreach (explode(';', $value) as $tag) {
            $pair = explode('=', $tag, 2);
            if (count($pair) === 2 && strtolower(trim($pair[0])) === 'p') {
                return trim($pair[1]) !== '';
            }
        }

        return false;
    }

    /** A DMARC record: `v=DMARC1` as its first tag (RFC 7489 §6.3). */
    public static function isDmarc(string $value): bool
    {
        return preg_match('/^v\s*=\s*DMARC1\s*(;|$)/i', trim($value)) === 1;
    }
}
