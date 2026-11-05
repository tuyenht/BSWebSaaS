<?php

declare(strict_types=1);

namespace App\Modules\Theme\Chrome;

/**
 * The site-ownership codes search engines and Facebook ask for (site_settings storefront /
 * verification): one token per provider, printed in the page head as a single meta tag. Only the
 * token is ever stored, never markup, and every token is checked again when the head is built, so a
 * hand-edited row cannot put anything but a plain code into a page.
 */
final class SiteVerification
{
    /** @var array<string, array{meta: string, pattern: string}> provider key → the meta name and the code's shape */
    public const array PROVIDERS = [
        'google' => ['meta' => 'google-site-verification', 'pattern' => '/\A[A-Za-z0-9_-]{20,}\z/'],
        'bing' => ['meta' => 'msvalidate.01', 'pattern' => '/\A[A-Za-z0-9_-]{12,}\z/'],
        'facebook' => ['meta' => 'facebook-domain-verification', 'pattern' => '/\A[a-z0-9]{20,}\z/i'],
    ];

    /**
     * What was typed or pasted, reduced to the code: spaces trimmed, and when a whole meta tag was
     * pasted, the value of its content attribute. Not yet checked: see valid().
     */
    public static function extract(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $raw, $m) === 1) {
            return trim($m[1]);
        }

        return $raw;
    }

    public static function valid(string $provider, string $token): bool
    {
        $rule = self::PROVIDERS[$provider] ?? null;

        return $rule !== null && preg_match($rule['pattern'], $token) === 1;
    }

    /**
     * The meta tags a page prints for a stored setting: providers switched on with a well-formed
     * token, in the fixed provider order. Anything else is skipped.
     *
     * @return list<array{name: string, content: string}>
     */
    public static function tags(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $tags = [];
        foreach (self::PROVIDERS as $provider => $rule) {
            $row = $stored[$provider] ?? null;
            $token = is_array($row) && ($row['on'] ?? false) === true && is_string($row['token'] ?? null) ? $row['token'] : '';
            if ($token !== '' && self::valid($provider, $token)) {
                $tags[] = ['name' => $rule['meta'], 'content' => $token];
            }
        }

        return $tags;
    }
}
