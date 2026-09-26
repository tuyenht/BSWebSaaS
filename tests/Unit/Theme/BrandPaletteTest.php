<?php

use App\Modules\Theme\Theming\BrandPalette;
use App\Modules\Theme\Theming\ReadableColor;

/*
 * One brand color → a ten-step palette (--brand-50 … --brand-900) for tints, fills and dark
 * accents (docs/notes/section-style-system.md §1). Built in OKLCH so every step keeps the brand's
 * hue and the steps are evenly spaced in perceived lightness; the brand itself sits unchanged at
 * the step closest to its own lightness, so "brand-600" can be exactly the color the tenant chose.
 */

$steps = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];

it('turns one brand color into ten valid colors, lightest to darkest', function () use ($steps): void {
    foreach (['#3b82f6', '#f59e0b', '#0f172a', '#16a34a', '#e11d48', '#ffffff', '#000000'] as $brand) {
        $palette = BrandPalette::from($brand);

        expect($palette)->not->toBeNull()
            ->and(array_keys((array) $palette))->toBe($steps);

        $previous = null;
        foreach ((array) $palette as $color) {
            expect($color)->toMatch('/^#[0-9a-f]{6}$/');
            // Darker at every step: contrast against white only grows.
            $ratio = (float) ReadableColor::ratio($color, '#ffffff');
            if ($previous !== null) {
                expect($ratio)->toBeGreaterThanOrEqual($previous);
            }
            $previous = $ratio;
        }
    }
});

it('keeps the brand color itself at the step closest to its lightness', function (): void {
    expect(BrandPalette::from('#3b82f6'))->toContain('#3b82f6')
        ->and(BrandPalette::from('#f59e0b'))->toContain('#f59e0b')
        ->and(BrandPalette::from('#0f172a'))->toContain('#0f172a');
});

it('keeps the hue: an amber brand gives amber tints and shades', function (): void {
    foreach ((array) BrandPalette::from('#f59e0b') as $step => $color) {
        [$red, $green, $blue] = [hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))];

        expect($red)->toBeGreaterThanOrEqual($green, "step {$step}")
            ->and($green)->toBeGreaterThanOrEqual($blue, "step {$step}");
    }
});

it('makes the light end light enough to carry dark text and the dark end dark enough for white text', function (): void {
    foreach (['#3b82f6', '#f59e0b', '#16a34a', '#e11d48'] as $brand) {
        $palette = (array) BrandPalette::from($brand);

        expect(ReadableColor::ratio('#111111', $palette[50]))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT)
            ->and(ReadableColor::ratio('#111111', $palette[100]))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT)
            ->and(ReadableColor::ratio('#ffffff', $palette[800]))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT)
            ->and(ReadableColor::ratio('#ffffff', $palette[900]))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
    }
});

it('gives nothing for a value that is not a hex color', function (string $value): void {
    expect(BrandPalette::from($value))->toBeNull();
})->with(['blue', 'rgb(0,0,255)', '#12', 'var(--x)', '']);

it('reads a short hex color', function (): void {
    expect(BrandPalette::from('#fff'))->toBe(BrandPalette::from('#ffffff'));
});
