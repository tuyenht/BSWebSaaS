<?php

use Illuminate\Support\Facades\File;

/*
 * Guard: admin views must not paint semantic status colors as TEXT or a SOLID BORDER with the raw
 * hue. The raw hues read 1.5–3.1:1 on white and their soft tints (success #0ab39c 2.6, info 3.0,
 * warning #f7b84b 1.5, danger #f06548 2.6–3.1) — below WCAG AA. The readable tier `-text` exists for
 * exactly this (>= 4.5:1 on white, the page background and the 10% tints, in both schemes); fills
 * keep the base hue, borders that are decorative alpha (`/NN`) are left alone.
 *
 * This is what keeps the P1.5 sweep from silently regressing — a new `text-admin-danger` in a view
 * fails here. Ratios + the token values live in scripts/contrast-check.mjs.
 */

$rawSemanticInk = static function (string $html): array {
    // A class token (with any Tailwind variant prefix) that colours text or a solid border with a
    // raw semantic hue: not the -text/-solid tier, not a /alpha decorative border, not bg-/ring-.
    preg_match_all(
        '/(?<![\w-])(?:[a-z-]+:)*(?:text|border)-admin-(?:success|info|warning|danger)(?![\w\/-])/',
        $html,
        $matches,
    );

    return $matches[0];
};

it('never paints a raw semantic hue as text or a solid border in an admin view', function () use ($rawSemanticInk): void {
    $files = File::allFiles(base_path('app/Modules/Admin/resources/views'));
    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $hits = $rawSemanticInk($file->getContents());

        if ($hits !== []) {
            $offenders[$file->getRelativePathname()] = array_values(array_unique($hits));
        }
    }

    expect($offenders)->toBe([], 'Use the -text tier (readable) instead of the raw hue: '.json_encode($offenders, JSON_UNESCAPED_SLASHES));
});
