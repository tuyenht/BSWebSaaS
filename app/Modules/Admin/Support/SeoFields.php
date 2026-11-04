<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

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

    /** Where typing stops in a field (its `maxlength`). */
    public static function hardCap(string $field): int
    {
        $limits = self::LIMITS[$field];

        return $limits['hard'] ?? (int) round($limits['max'] * 1.25);
    }

    /**
     * A Google title or description as one line: every run of line breaks, tabs, other control
     * characters and spaces becomes one space, and the ends are trimmed. Google shows these fields on one line,
     * and a stray control character would land in the page's <head>.
     */
    public static function singleLine(string $value): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\s]+/u', ' ', $value));
    }
}
