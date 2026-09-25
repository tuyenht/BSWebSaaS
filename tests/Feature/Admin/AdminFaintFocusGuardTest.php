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

it('never suppresses focus with a faint or missing ring (outline-none needs a >=3:1 ring)', function () use ($adminViews): void {
    // A focus ring that fails 3:1 is worse than none — it looks like a real ring but is invisible.
    // So `outline-none` may only accompany a SOLID ring that clears 3:1; otherwise drop outline-none
    // and let the admin-wide :focus-visible outline (2px accent) show. Faint alpha rings
    // (ring-<color>/NN) and ring-0 fail. The solid rings below are measured in scripts/contrast-check.mjs.
    $solidRings = ['ring-topbar-fg'];   // #878a99 on the topbar: 3.42 light / 4.00 dark

    $offenders = [];

    foreach ($adminViews() as $file) {
        foreach (explode("\n", $file->getContents()) as $index => $line) {
            if (! str_contains($line, 'outline-none')) {
                continue;
            }

            // A justified exception is allowed only with an inline `focus-ring-ok` comment.
            if (str_contains($line, 'focus-ring-ok')) {
                continue;
            }

            $hasSolidRing = false;
            foreach ($solidRings as $ring) {
                if (str_contains($line, $ring)) {
                    $hasSolidRing = true;
                    break;
                }
            }

            if (! $hasSolidRing) {
                $offenders[] = $file->getRelativePathname().':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'outline-none must pair with a solid ring >=3:1 ('.implode(', ', $solidRings).') or be dropped for the shared :focus-visible outline. Faint ring-*/NN and ring-0 fail; a justified exception needs an inline `focus-ring-ok` comment. Offenders: '.json_encode($offenders, JSON_UNESCAPED_SLASHES));
});
