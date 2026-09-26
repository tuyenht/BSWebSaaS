<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

/**
 * One brand color → the ten-step palette `--brand-50 … --brand-900` (docs/notes/section-style-system.md
 * §1) for tints, fills and dark accents. Built in OKLCH: every step keeps the brand's hue, the steps
 * are evenly spaced in perceived lightness, and chroma tapers towards white and black so the light
 * and dark ends stay usable behind text. The brand itself sits unchanged at the step whose target
 * lightness is closest to its own. Out-of-gamut colors lose chroma (never hue or lightness) until
 * they fit sRGB. Pure math — no settings, no request state.
 */
final class BrandPalette
{
    /** Target OKLCH lightness per step (the ramp of Tailwind v4's default palettes). */
    private const array LIGHTNESS = [
        50 => 0.971, 100 => 0.936, 200 => 0.885, 300 => 0.808, 400 => 0.707,
        500 => 0.623, 600 => 0.546, 700 => 0.488, 800 => 0.424, 900 => 0.379,
    ];

    /** Share of the brand's chroma each step keeps: vivid in the middle, muted at the ends. */
    private const array CHROMA = [
        50 => 0.12, 100 => 0.25, 200 => 0.45, 300 => 0.7, 400 => 0.9,
        500 => 1.0, 600 => 1.0, 700 => 0.9, 800 => 0.8, 900 => 0.7,
    ];

    /**
     * @return array<int, string>|null step (50 … 900) → #rrggbb, or null when the brand is not a hex color
     */
    public static function from(string $brand): ?array
    {
        $rgb = self::parse($brand);
        if ($rgb === null) {
            return null;
        }
        [$lightness, $chroma, $hue] = self::toOklch($rgb);

        $anchor = self::closestStep($lightness);
        $palette = [];
        foreach (self::LIGHTNESS as $step => $target) {
            $palette[$step] = $step === $anchor
                ? self::hex($rgb)
                : self::hex(self::fromOklchInGamut($target, $chroma * self::CHROMA[$step], $hue));
        }

        return $palette;
    }

    private static function closestStep(float $lightness): int
    {
        $best = 500;
        $distance = INF;
        foreach (self::LIGHTNESS as $step => $target) {
            if (abs($target - $lightness) < $distance) {
                $distance = abs($target - $lightness);
                $best = $step;
            }
        }

        return $best;
    }

    /**
     * @param  array{float, float, float}  $rgb  gamma-encoded sRGB, 0..1
     * @return array{float, float, float} lightness, chroma, hue (radians)
     */
    private static function toOklch(array $rgb): array
    {
        [$r, $g, $b] = array_map(self::linear(...), $rgb);

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        [$l, $m, $s] = [self::cbrt($l), self::cbrt($m), self::cbrt($s)];

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        return [$lightness, sqrt($a * $a + $bb * $bb), atan2($bb, $a)];
    }

    /**
     * The OKLCH color in sRGB, with chroma reduced (binary search) until it fits the gamut.
     *
     * @return array{float, float, float}
     */
    private static function fromOklchInGamut(float $lightness, float $chroma, float $hue): array
    {
        $fits = self::fromOklch($lightness, $chroma, $hue);
        if ($fits !== null) {
            return $fits;
        }

        $low = 0.0;
        $high = $chroma;
        $best = self::fromOklch($lightness, 0.0, $hue) ?? [$lightness, $lightness, $lightness];
        for ($i = 0; $i < 24; $i++) {
            $mid = ($low + $high) / 2;
            $candidate = self::fromOklch($lightness, $mid, $hue);
            if ($candidate === null) {
                $high = $mid;
            } else {
                $best = $candidate;
                $low = $mid;
            }
        }

        return $best;
    }

    /**
     * @return array{float, float, float}|null gamma-encoded sRGB, or null when outside the gamut
     */
    private static function fromOklch(float $lightness, float $chroma, float $hue): ?array
    {
        $a = $chroma * cos($hue);
        $b = $chroma * sin($hue);

        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $linear = [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];

        $rgb = [];
        foreach ($linear as $channel) {
            if ($channel < -0.0001 || $channel > 1.0001) {
                return null;
            }
            $rgb[] = self::gamma(max(0.0, min(1.0, $channel)));
        }

        return [$rgb[0], $rgb[1], $rgb[2]];
    }

    private static function linear(float $channel): float
    {
        return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }

    private static function gamma(float $channel): float
    {
        return $channel <= 0.0031308 ? $channel * 12.92 : 1.055 * ($channel ** (1 / 2.4)) - 0.055;
    }

    private static function cbrt(float $value): float
    {
        return $value < 0 ? -((-$value) ** (1 / 3)) : $value ** (1 / 3);
    }

    /**
     * @return array{float, float, float}|null
     */
    private static function parse(string $color): ?array
    {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($color), $match) !== 1) {
            return null;
        }
        $hex = strtolower($match[1]);
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }

    /**
     * @param  array{float, float, float}  $rgb
     */
    private static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(
            static fn (float $channel): int => (int) round(max(0.0, min(1.0, $channel)) * 255),
            $rgb,
        ));
    }
}
