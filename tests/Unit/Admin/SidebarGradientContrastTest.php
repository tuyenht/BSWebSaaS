<?php

use App\Modules\Theme\Theming\ReadableColor;

/*
 * The gradient sidebars paint menu text white — at 85% on a resting item, full white on the
 * active row's white/15% fill — straight over the band. Every point of the band must keep both
 * at WCAG AA (4.5:1). Read from the stylesheet itself so a retuned stop cannot slip past.
 */

/**
 * @return array<string, list<string>> data-sidebar value → hex stops, in order
 */
function sidebarGradients(): array
{
    $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/admin-chrome.css');
    preg_match_all("/:root\\[data-sidebar='(gradient[\\w-]*)'\\] \\.admin-sidebar \\{\\s*background-image: linear-gradient\\(to right, ([^)]*)\\);/", $css, $rules, PREG_SET_ORDER);

    $gradients = [];
    foreach ($rules as [, $variant, $stops]) {
        $gradients[$variant] = array_map('trim', explode(',', $stops));
    }

    return $gradients;
}

/** @return array{0: int, 1: int, 2: int} */
function sidebarRgb(string $hex): array
{
    return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
}

/**
 * $top at $alpha over $bottom (gamma-encoded sRGB, as the browser composites), as #rrggbb.
 *
 * @param  array{0: int, 1: int, 2: int}  $top
 * @param  array{0: int, 1: int, 2: int}  $bottom
 */
function sidebarBlend(array $top, float $alpha, array $bottom): string
{
    return sprintf('#%02x%02x%02x', ...array_map(
        static fn (int $i): int => (int) round($top[$i] * $alpha + $bottom[$i] * (1 - $alpha)),
        [0, 1, 2],
    ));
}

it('declares the four gradient sidebars', function (): void {
    expect(array_keys(sidebarGradients()))->toBe(['gradient', 'gradient-2', 'gradient-3', 'gradient-4']);
});

it('keeps resting and active menu text at AA across every gradient band', function (string $variant, array $stops): void {
    $white = [255, 255, 255];

    for ($step = 0; $step <= 20; $step++) {
        $t = $step / 20;
        $point = sidebarBlend(sidebarRgb($stops[1]), $t, sidebarRgb($stops[0])); // sRGB interpolation, like the browser
        $band = sidebarRgb($point);

        $resting = ReadableColor::ratio(sidebarBlend($white, 0.85, $band), $point);
        $active = ReadableColor::ratio('#ffffff', sidebarBlend($white, 0.15, $band));

        expect(floor((float) $resting * 100) / 100)->toBeGreaterThanOrEqual(4.5, "{$variant} resting at {$t}")
            ->and(floor((float) $active * 100) / 100)->toBeGreaterThanOrEqual(4.5, "{$variant} active at {$t}");
    }
})->with(function (): array {
    $cases = [];
    foreach (sidebarGradients() as $variant => $stops) {
        $cases[$variant] = [$variant, $stops];
    }

    return $cases;
});

it('paints gradient menu text at the alphas the check above assumes', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/admin-chrome.css');
    preg_match("/:root\\[data-sidebar\\^='gradient'\\] \\{(.*?)\\}/s", $css, $block);

    expect($block[1] ?? '')
        ->toContain('--color-sidebar-fg: rgb(255 255 255 / 0.85);')
        ->toContain('--color-sidebar-fg-active: #ffffff;')
        ->toContain('--color-sidebar-active: rgb(255 255 255 / 0.15);')
        ->toContain('--color-sidebar-hover: rgb(255 255 255 / 0.15);');
});
