<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

/**
 * WCAG 2 contrast for the storefront's DERIVED color roles (seed → role, docs/notes/section-style-
 * system.md §1). A tenant chooses its seeds freely; a role that must stay readable for ANY seed — the
 * brand color used as text — cannot be written as CSS, because CSS can mix colors but cannot loop
 * until a contrast target is met. So it is computed here, from the seeds, per request.
 *
 * Mixing is done in gamma-encoded sRGB, the same space as the stylesheet's color-mix(in srgb, …).
 */
final class ReadableColor
{
    /** WCAG 2 AA minimum for normal-size text. */
    public const float AA_NORMAL_TEXT = 4.5;

    /** Attempts on the way to black/white: each moves 1/20 (5%) further. */
    private const int STEPS = 20;

    /** WCAG 2 contrast ratio of two hex colors (#rgb / #rrggbb), or null when either is not one. */
    public static function ratio(string $a, string $b): ?float
    {
        $first = self::parse($a);
        $second = self::parse($b);

        if ($first === null || $second === null) {
            return null;
        }

        $lighter = max(self::luminance($first), self::luminance($second));
        $darker = min(self::luminance($first), self::luminance($second));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * $color moved toward black (on a light background) or white (on a dark one) until it reaches
     * $minimum contrast against $background — the hue kept, only the lightness shifted. Returned
     * unchanged (as lowercase #rrggbb) when it already reads well. Null when either input is not a
     * hex color (a named or rgb() seed): the caller decides the fallback.
     */
    public static function on(string $color, string $background, float $minimum = self::AA_NORMAL_TEXT): ?string
    {
        return self::onAll($color, [$background], $minimum);
    }

    /**
     * The same, for text that sits on several backgrounds (a card and the page around it): the
     * first step that reaches $minimum against EVERY one of them. The first background sets the
     * direction, so list the main surface first. Null when $color or any background is not a hex
     * color, or when no background is given.
     *
     * @param  list<string>  $backgrounds
     */
    public static function onAll(string $color, array $backgrounds, float $minimum = self::AA_NORMAL_TEXT): ?string
    {
        $rgb = self::parse($color);
        $surfaces = [];

        foreach ($backgrounds as $background) {
            $surface = self::parse($background);

            if ($surface === null) {
                return null;
            }

            $surfaces[] = $surface;
        }

        if ($rgb === null || $surfaces === []) {
            return null;
        }

        // Head for whichever extreme contrasts more with the main surface (black wins above
        // luminance ≈0.18, not 0.5 — a mid-grey surface still wants dark text).
        $surfaceLuminance = self::luminance($surfaces[0]);
        $target = ($surfaceLuminance + 0.05) / 0.05 >= 1.05 / ($surfaceLuminance + 0.05)
            ? [0.0, 0.0, 0.0]
            : [255.0, 255.0, 255.0];

        for ($step = 0; $step <= self::STEPS; $step++) {
            $weight = $step / self::STEPS;
            $candidate = [
                $rgb[0] + ($target[0] - $rgb[0]) * $weight,
                $rgb[1] + ($target[1] - $rgb[1]) * $weight,
                $rgb[2] + ($target[2] - $rgb[2]) * $weight,
            ];
            $hex = self::hex($candidate);

            if (self::readsOnEvery($hex, $backgrounds, $minimum)) {
                return $hex;
            }
        }

        return self::hex($target);
    }

    /**
     * $first and $second mixed in gamma-encoded sRGB, $firstShare of the first — what the
     * stylesheet's color-mix(in srgb, first N%, second) paints. Null when either is not a hex color.
     */
    public static function mix(string $first, string $second, float $firstShare): ?string
    {
        $one = self::parse($first);
        $two = self::parse($second);

        if ($one === null || $two === null) {
            return null;
        }

        return self::hex([
            $one[0] * $firstShare + $two[0] * (1 - $firstShare),
            $one[1] * $firstShare + $two[1] * (1 - $firstShare),
            $one[2] * $firstShare + $two[2] * (1 - $firstShare),
        ]);
    }

    /** @param  list<string>  $backgrounds */
    private static function readsOnEvery(string $hex, array $backgrounds, float $minimum): bool
    {
        foreach ($backgrounds as $background) {
            if ((float) self::ratio($hex, $background) < $minimum) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: float, 1: float, 2: float}|null */
    private static function parse(string $color): ?array
    {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color, $match) !== 1) {
            return null;
        }

        $digits = strlen($match[1]) === 3
            ? $match[1][0].$match[1][0].$match[1][1].$match[1][1].$match[1][2].$match[1][2]
            : $match[1];

        return [(float) hexdec(substr($digits, 0, 2)), (float) hexdec(substr($digits, 2, 2)), (float) hexdec(substr($digits, 4, 2))];
    }

    /** @param  array{0: float, 1: float, 2: float}  $rgb */
    private static function luminance(array $rgb): float
    {
        $channel = static function (float $value): float {
            $srgb = $value / 255;

            return $srgb <= 0.04045 ? $srgb / 12.92 : (($srgb + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }

    /** @param  array{0: float, 1: float, 2: float}  $rgb */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', (int) round($rgb[0]), (int) round($rgb[1]), (int) round($rgb[2]));
    }
}
