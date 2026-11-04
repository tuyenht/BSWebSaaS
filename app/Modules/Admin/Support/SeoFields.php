<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Tenancy\Context\ResolvedHost;

/**
 * The length limits of the short excerpt and the Google title and description: the one table the
 * shared "Hiện thế nào trên Google" part (<x-bs-admin::seo-fields>) reads for its meters, its
 * Google preview and its typing caps. It mirrors the design's limit table (content-seo.js LIMITS)
 * field for field, so no screen declares its own numbers.
 *
 *   min      below it the text is too short to say much
 *   best     the range that reads best (green)
 *   display  where Google (or the news card) cuts the text when it shows it
 *   max      where the text should stop (red above it)
 *   hard     where typing stops; without it, a quarter above `max`
 *
 * Every limit stays within the 255-character columns the values are stored in.
 */
final class SeoFields
{
    /** @var array<string, array{min: int, best: array{0: int, 1: int}, display: int, max: int, hard?: int}> */
    public const array LIMITS = [
        'excerpt' => ['min' => 80, 'best' => [120, 200], 'display' => 200, 'max' => 240, 'hard' => 255],
        'seoTitle' => ['min' => 20, 'best' => [35, 60], 'display' => 60, 'max' => 80],
        'seoDesc' => ['min' => 70, 'best' => [150, 160], 'display' => 160, 'max' => 200],
    ];

    /** A stand-in slug for composing the path around it; never shown. */
    private const string SLUG_MARK = 'x';

    /** Where typing stops in a field (its `maxlength`). */
    public static function hardCap(string $field): int
    {
        $limits = self::LIMITS[$field];

        return $limits['hard'] ?? (int) round($limits['max'] * 1.25);
    }

    /**
     * A Google title or description as one line: invisible format characters (zero-width spaces,
     * right-to-left overrides) are removed, every run of line breaks, tabs, other control characters
     * and spaces becomes one space, and the ends are trimmed. Google shows these fields on one line,
     * and a stray control or direction character would land in the page's <head>.
     */
    public static function singleLine(string $value): string
    {
        // The joiners (U+200C, U+200D) stay: they hold emoji sequences and some scripts together.
        $value = preg_replace('/(?![\x{200C}\x{200D}])\p{Cf}/u', '', $value) ?? $value;

        return trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\s]+/u', ' ', $value) ?? $value);
    }

    /**
     * Where the Google preview's address starts: the site's primary domain — the one address its
     * public pages live on (resolved for this request by SetCurrentSite, no query) — falling back to
     * the host the editor is open on outside a resolved request.
     */
    public static function publicHost(): string
    {
        $resolved = request()->attributes->get(ResolvedHost::class);

        return $resolved instanceof ResolvedHost ? $resolved->primaryHost : request()->getHost();
    }

    /**
     * The Google preview's address around the slug, for the browser to finish as the slug is typed:
     * `base` is the host, the language prefix and the path PathBuilder composes before the slug
     * (`anvinh.vn/en/tin-tuc/`); `home` is the address the home slug gets instead (pages only).
     *
     * @param  string  $pathBeforeSlug  the public path up to the slug, '/' for a flat one
     * @return array{base: string, home: string|null, homeSlug: string}
     */
    public static function address(string $host, string $pathBeforeSlug, string $locale, bool $hasHome): array
    {
        $paths = app(PathBuilder::class);
        $marked = $paths->publicUrl(rtrim($pathBeforeSlug, '/').'/'.self::SLUG_MARK, $locale);

        return [
            'base' => $host.substr($marked, 0, -strlen(self::SLUG_MARK)),
            'home' => $hasHome ? $host.$paths->publicUrl('/', $locale) : null,
            'homeSlug' => PathBuilder::HOME_SLUG,
        ];
    }
}
