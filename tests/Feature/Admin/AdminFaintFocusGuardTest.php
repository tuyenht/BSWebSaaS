<?php

use Illuminate\Support\Facades\File;

/*
 * Guard: admin views keep two AA fixes from regressing.
 *
 * 1. text-body-faint (#adb5bd, ~1.9:1 on white) must not colour meaningful text or an interactive
 *    control — those need >= 4.5:1 (text) / >= 3:1 (non-text). Use text-body-muted (#6b6e7f). The one
 *    allowed use is `placeholder:text-body-faint`: placeholder text is a separate, deliberate cue.
 * 2. A control must not set `outline-none` (any variant) unless it draws its own focus:ring — a bare
 *    outline-none cancels the admin-wide :focus-visible outline (app.css) for keyboard users.
 *
 * Token values + ratios live in scripts/contrast-check.mjs and resources/css.
 */

$adminViews = static function (): array {
    return array_filter(
        File::allFiles(base_path('app/Modules/Admin/resources/views')),
        static fn ($f) => $f->getExtension() === 'php',
    );
};

it('never colours meaningful text with the faint tier (placeholder aside)', function () use ($adminViews): void {
    $offenders = [];

    foreach ($adminViews() as $file) {
        if (preg_match_all('/(?<!placeholder:)text-body-faint/', $file->getContents(), $m) && $m[0] !== []) {
            $offenders[$file->getRelativePathname()] = count($m[0]);
        }
    }

    expect($offenders)->toBe([], 'Use text-body-muted for meaningful text, not faint: '.json_encode($offenders, JSON_UNESCAPED_SLASHES));
});

it('never suppresses the shared focus ring with an unpaired outline-none', function () use ($adminViews): void {
    $offenders = [];

    foreach ($adminViews() as $file) {
        foreach (explode("\n", $file->getContents()) as $index => $line) {
            if (str_contains($line, 'outline-none') && preg_match('/focus(?:-visible)?:ring/', $line) === 0) {
                $offenders[] = $file->getRelativePathname().':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'Drop outline-none (the shared :focus-visible ring covers it) or pair it with focus:ring: '.json_encode($offenders, JSON_UNESCAPED_SLASHES));
});
