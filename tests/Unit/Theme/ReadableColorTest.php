<?php

use App\Modules\Theme\Theming\ReadableColor;

/*
 * A tenant picks its brand color freely, and the storefront also uses that color as TEXT on light
 * surfaces (breadcrumb links, the contact email, the sector label, the 404 code). A light brand —
 * An Vinh's amber — then reads at ≈2.1:1 where WCAG AA needs 4.5:1. CSS can mix colors but cannot
 * loop until a contrast target is met, so the readable variant is derived here from the seeds.
 */

it('measures WCAG contrast the way the spec defines it', function (): void {
    expect(round((float) ReadableColor::ratio('#000000', '#ffffff'), 2))->toBe(21.0)
        ->and(round((float) ReadableColor::ratio('#f59e0b', '#ffffff'), 2))->toBe(2.15)
        ->and(round((float) ReadableColor::ratio('#3b82f6', '#fff'), 2))->toBe(3.68);
});

it('darkens a brand color that is too light to read on a light surface', function (): void {
    $readable = ReadableColor::on('#f59e0b', '#ffffff');

    expect($readable)->not->toBeNull()
        ->not->toBe('#f59e0b')
        ->and(ReadableColor::ratio((string) $readable, '#ffffff'))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT)
        // Still amber, only darker: red channel stays the dominant one.
        ->and(hexdec(substr((string) $readable, 1, 2)))->toBeGreaterThan(hexdec(substr((string) $readable, 5, 2)));
});

it('leaves a brand color that already reads well untouched', function (): void {
    expect(ReadableColor::on('#1E3A8A', '#ffffff'))->toBe('#1e3a8a');
});

it('lightens instead when the surface is dark', function (): void {
    $readable = ReadableColor::on('#1e3a8a', '#111111');

    expect(ReadableColor::ratio((string) $readable, '#111111'))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
});

it('gives up (null) on a seed that is not a hex color, so the caller picks the fallback', function (): void {
    expect(ReadableColor::on('rebeccapurple', '#ffffff'))->toBeNull()
        ->and(ReadableColor::on('#f59e0b', 'rgb(255, 255, 255)'))->toBeNull()
        ->and(ReadableColor::ratio('#12345', '#ffffff'))->toBeNull();
});

it('reads at AA on every background it is given, not only the first', function (): void {
    // An amber brand on a white card and on the page tint around it (#f8f8f9).
    $readable = ReadableColor::onAll('#f59e0b', ['#ffffff', '#f8f8f9']);

    expect(ReadableColor::ratio((string) $readable, '#ffffff'))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT)
        ->and(ReadableColor::ratio((string) $readable, '#f8f8f9'))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT)
        // The white-only answer falls short on the page tint — why both are checked.
        ->and(ReadableColor::ratio((string) ReadableColor::on('#f59e0b', '#ffffff'), '#f8f8f9'))->toBeLessThan(ReadableColor::AA_NORMAL_TEXT);
});

it('gives up (null) when any background is not a hex color, or none is given', function (): void {
    expect(ReadableColor::onAll('#f59e0b', ['#ffffff', 'white']))->toBeNull()
        ->and(ReadableColor::onAll('#f59e0b', []))->toBeNull();
});

it('mixes two colors the way color-mix(in srgb) paints them', function (): void {
    expect(ReadableColor::mix('#ffffff', '#0f172a', 0.97))->toBe('#f8f8f9')
        ->and(ReadableColor::mix('#ffffff', 'white', 0.97))->toBeNull();
});

it('darkens on a mid-grey surface, where black contrasts more than white', function (): void {
    // Luminance ≈0.3: black reaches ~7:1 here, white only ~3:1.
    $readable = ReadableColor::on('#9ca3af', '#949494');

    expect(ReadableColor::ratio((string) $readable, '#949494'))->toBeGreaterThanOrEqual(ReadableColor::AA_NORMAL_TEXT);
});
