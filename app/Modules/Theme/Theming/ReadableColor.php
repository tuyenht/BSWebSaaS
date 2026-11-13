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

    /** A fill's saturation below this is a grey: its dark shade stays grey. */
    private const float NEUTRAL_SATURATION = 0.1;

    /** Least saturation of a dark shade of a coloured fill (a deep tone reads as a colour, not mud). */
    private const float SHADE_SATURATION = 0.9;

    /** Lightness a dark shade starts from, before it is lowered. */
    private const float SHADE_START = 0.14;

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
     * A fill darkened (in OKLab, toward a dark slate) just until white marks on it reach 3:1, with a small
     * margin; a fill white already reads on, or anything that is not #rrggbb, is returned unchanged.
     * Each step is rounded to a real 8-bit colour before it is tested, so the answer is what is painted.
     */
    public static function inkUnder(string $hex): string
    {
        if (preg_match('/\A#[0-9a-f]{6}\z/i', $hex) !== 1) {
            return $hex;
        }

        $rgb = [self::toLinear(hexdec(substr($hex, 1, 2)) / 255), self::toLinear(hexdec(substr($hex, 3, 2)) / 255), self::toLinear(hexdec(substr($hex, 5, 2)) / 255)];
        if (self::whiteContrast($rgb) >= 3) {
            return $hex;
        }

        $from = self::toOklab($rgb);
        $to = self::toOklab([self::toLinear(15 / 255), self::toLinear(23 / 255), self::toLinear(42 / 255)]);
        for ($p = 1; $p < 60; $p++) {
            $t = $p / 100;
            $mixed = self::fromOklab([
                $from[0] * (1 - $t) + $to[0] * $t,
                $from[1] * (1 - $t) + $to[1] * $t,
                $from[2] * (1 - $t) + $to[2] * $t,
            ]);
            $bytes = array_map(static fn (float $c): int => (int) round(self::fromLinear($c) * 255), $mixed);
            $painted = [self::toLinear($bytes[0] / 255), self::toLinear($bytes[1] / 255), self::toLinear($bytes[2] / 255)];
            if (self::whiteContrast($painted) >= 3.05) {
                return sprintf('#%02x%02x%02x', $bytes[0], $bytes[1], $bytes[2]);
            }
        }

        return $hex;
    }

    private static function toLinear(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function fromLinear(float $c): float
    {
        $c = max(0.0, min(1.0, $c));

        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055;
    }

    /**
     * Contrast of white on a colour given as linear sRGB.
     *
     * @param  array{0: float, 1: float, 2: float}  $v
     */
    private static function whiteContrast(array $v): float
    {
        return 1.05 / (0.2126 * $v[0] + 0.7152 * $v[1] + 0.0722 * $v[2] + 0.05);
    }

    private static function cbrt(float $x): float
    {
        return $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $v  linear sRGB
     * @return array{0: float, 1: float, 2: float}
     */
    private static function toOklab(array $v): array
    {
        $l = self::cbrt(0.4122214708 * $v[0] + 0.5363325363 * $v[1] + 0.0514459929 * $v[2]);
        $m = self::cbrt(0.2119034982 * $v[0] + 0.6806995451 * $v[1] + 0.1073969566 * $v[2]);
        $q = self::cbrt(0.0883024619 * $v[0] + 0.2817188376 * $v[1] + 0.6299787005 * $v[2]);

        return [
            0.2104542553 * $l + 0.793617785 * $m - 0.0040720468 * $q,
            1.9779984951 * $l - 2.428592205 * $m + 0.4505937099 * $q,
            0.0259040371 * $l + 0.7827717662 * $m - 0.808675766 * $q,
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $a  OKLab
     * @return array{0: float, 1: float, 2: float} linear sRGB, each channel kept within 0..1
     */
    private static function fromOklab(array $a): array
    {
        $l = ($a[0] + 0.3963377774 * $a[1] + 0.2158037573 * $a[2]) ** 3;
        $m = ($a[0] - 0.1055613458 * $a[1] - 0.0638541728 * $a[2]) ** 3;
        $q = ($a[0] - 0.0894841775 * $a[1] - 1.291485548 * $a[2]) ** 3;

        return [
            max(0.0, min(1.0, 4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $q)),
            max(0.0, min(1.0, -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $q)),
            max(0.0, min(1.0, -0.0041960863 * $l - 0.7034186147 * $m + 1.707614701 * $q)),
        ];
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

    /**
     * A very dark shade of the same hue as $fill that reads at $minimum on it: saturation raised
     * (a neutral fill stays neutral) and lightness lowered from a deep tone step by step until it
     * does. Null when $fill is not hex or no shade reaches it.
     */
    public static function darkShade(string $fill, float $minimum = self::AA_NORMAL_TEXT): ?string
    {
        $rgb = self::parse($fill);
        if ($rgb === null) {
            return null;
        }

        [$r, $g, $b] = [$rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255];
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;
        $hue = 0.0;
        if ($delta > 0) {
            $hue = match ($max) {
                $r => fmod(($g - $b) / $delta, 6),
                $g => ($b - $r) / $delta + 2,
                default => ($r - $g) / $delta + 4,
            } * 60;
            $hue = $hue < 0 ? $hue + 360 : $hue;
        }
        $lightness = ($max + $min) / 2;
        $saturation = $delta === 0.0 ? 0.0 : $delta / (1 - abs(2 * $lightness - 1));
        $saturation = $saturation < self::NEUTRAL_SATURATION ? $saturation : max($saturation, self::SHADE_SATURATION);

        for ($tone = self::SHADE_START; $tone >= 0.0; $tone -= 0.01) {
            $chroma = (1 - abs(2 * $tone - 1)) * $saturation;
            $x = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
            $m = $tone - $chroma / 2;
            [$cr, $cg, $cb] = match ((int) floor($hue / 60) % 6) {
                0 => [$chroma, $x, 0.0],
                1 => [$x, $chroma, 0.0],
                2 => [0.0, $chroma, $x],
                3 => [0.0, $x, $chroma],
                4 => [$x, 0.0, $chroma],
                default => [$chroma, 0.0, $x],
            };
            $shade = self::hex([($cr + $m) * 255, ($cg + $m) * 255, ($cb + $m) * 255]);
            if ((float) self::ratio($shade, $fill) >= $minimum) {
                return $shade;
            }
        }

        return null;
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
