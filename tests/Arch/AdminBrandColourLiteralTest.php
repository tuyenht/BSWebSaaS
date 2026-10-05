<?php

use Illuminate\Support\Facades\File;

/*
 * The admin brand colour lives in one token (--color-admin-primary, resources/css/app.css; ink
 * uses --color-accent). A view that copies its hex or rgb value keeps the old colour when the
 * palette changes, which has already happened twice. Views must use the token instead:
 * bg-admin-primary / text-accent, or var(--color-accent) inside an arbitrary value.
 */

it('copies the admin brand colour into no Blade view', function (): void {
    $violations = [];
    foreach (['app', 'resources', 'themes'] as $dir) {
        if (! File::isDirectory(base_path($dir))) {
            continue;
        }
        foreach (File::allFiles(base_path($dir)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            if (preg_match('/#405189|64\s*,\s*81\s*,\s*137/i', File::get($file->getPathname()))) {
                $violations[] = $dir.'/'.$file->getRelativePathname();
            }
        }
    }

    expect($violations)->toBe([]);
});
