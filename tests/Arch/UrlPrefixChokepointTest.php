<?php

use Illuminate\Support\Facades\File;

/*
 * C2 — URL i18n chokepoint (design.md §10/ADR-11): the locale prefix is applied in EXACTLY
 * one place, PathBuilder::publicUrl. No storefront emit-site may concatenate a locale
 * variable into a path by hand — content-path slug concatenation ('/'.$categorySlug) is
 * fine; this guards the LOCALE variable specifically, catching future regressions.
 */

it('builds locale-prefixed URLs only through PathBuilder::publicUrl', function (): void {
    $targets = [
        base_path('app/Modules/Storefront/Http/Controllers/StorefrontController.php'),
        base_path('app/Modules/Cms/Navigation/MenuResolver.php'),
    ];
    foreach (File::allFiles(base_path('themes/default')) as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $targets[] = $file->getPathname();
        }
    }

    // A locale variable ($…locale) concatenated with a slash, or interpolated next to a
    // leading slash. PathBuilder::publicUrl is the only sanctioned prefixer.
    $forbidden = [
        '/[\'"]\/[\'"]\s*\.\s*\$\w*[lL]ocale\b/',   // '/' . $locale
        '/\$\w*[lL]ocale\b\s*\.\s*[\'"]\//',        // $locale . '/'
        '/[\'"]\/\{?\$\w*[lL]ocale\}?/',            // "/$locale" or "/{$locale}"
    ];

    $violations = [];
    foreach ($targets as $path) {
        foreach (explode("\n", File::get($path)) as $i => $line) {
            foreach ($forbidden as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $violations[] = basename($path).':'.($i + 1).' → '.trim($line);
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
