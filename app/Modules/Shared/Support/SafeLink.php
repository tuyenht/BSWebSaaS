<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * The one check every link taken from site settings passes before it reaches an href: a web link
 * (http/https with a host) or a site-relative path, nothing else. A path that starts with `//` or
 * `/\` is a link to another host in every browser, so it is refused like `javascript:` or `data:`.
 * Phone and email links are built from a validated value, never passed through.
 */
final class SafeLink
{
    /** Longest link accepted; anything longer is not a hand-entered link. */
    public const int MAX_LENGTH = 2048;

    /**
     * The link when it is safe to print, else null.
     *
     * @param  list<string>  $hosts  when not empty, an absolute link must be on one of these hosts (or a subdomain)
     */
    public static function href(mixed $url, array $hosts = []): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F\\\\]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return $hosts === [] || self::onHost($host, $hosts) ? $url : null;
    }

    /** A tel: link from the digits (and a leading +) of a phone number; null when none are left. */
    public static function tel(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }
        // "+84 (0)90…": the bracketed trunk 0 is not dialled after a country code (kept without one)
        $phone = (string) preg_replace('/^(\s*\+\d{1,3})\s*\(\s*0\s*\)/', '$1', $phone);
        $digits = (string) preg_replace('/[^\d+]/', '', $phone);
        $digits = ($digits !== '' && $digits[0] === '+' ? '+' : '').str_replace('+', '', $digits);

        return preg_match('/^\+?\d{3,20}$/', $digits) === 1 ? 'tel:'.$digits : null;
    }

    /** A mailto: link for a valid email address, else null. */
    public static function mailto(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }
        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:'.$email : null;
    }

    /**
     * @param  list<string>  $hosts
     */
    private static function onHost(string $host, array $hosts): bool
    {
        foreach ($hosts as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }
}
