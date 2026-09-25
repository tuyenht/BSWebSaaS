<?php

use Illuminate\Support\Facades\File;

/*
 * Guard: admin views must not paint navy (admin-primary #405189) as TEXT or a SOLID BORDER. Raw
 * admin-primary does not flip, so it stays navy in dark mode and fails on the dark surface (≈1.5:1).
 * The `accent` role is the readable ink — #405189 in light (identical), #96a1c0 in dark — so
 * text-accent / border-accent read in both schemes. admin-primary stays for FILLS (bg-admin-primary*).
 *
 * The one allowed solid `border-admin-primary` is when it matches a solid navy FILL under white text
 * (`border-admin-primary bg-admin-primary text-white`) — there the border must equal the fill.
 * Decorative alpha borders (border-admin-primary/NN) are left alone.
 */

it('never paints navy (admin-primary) as text or a solid border in an admin view', function (): void {
    $files = File::allFiles(base_path('app/Modules/Admin/resources/views'));
    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (explode("\n", $file->getContents()) as $index => $line) {
            $where = $file->getRelativePathname().':'.($index + 1);

            // Navy as text ink — never allowed; use text-accent.
            if (preg_match('#(?<![\w-])(?:[a-z-]+:)*text-admin-primary(?![\w/-])#', $line) === 1) {
                $offenders[] = $where.' (text)';
            }

            // Solid navy border (not /alpha) — allowed only when it matches a solid navy fill on the
            // same line (bg-admin-primary followed by a space or quote, i.e. not a /NN tint).
            $hasSolidBorder = preg_match('#(?<![\w-])(?:[a-z-]+:)*border-admin-primary(?![\w/-])#', $line) === 1;
            $matchesSolidFill = preg_match('#bg-admin-primary(?=[ "\'])#', $line) === 1;
            if ($hasSolidBorder && ! $matchesSolidFill) {
                $offenders[] = $where.' (border)';
            }
        }
    }

    expect($offenders)->toBe([], 'Use the accent role (text-accent / border-accent — flips in dark), not raw admin-primary, as ink: '.json_encode($offenders, JSON_UNESCAPED_SLASHES));
});
