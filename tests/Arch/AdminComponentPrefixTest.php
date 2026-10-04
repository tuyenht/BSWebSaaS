<?php

use Illuminate\Support\Facades\File;

/*
 * The admin UI components answer to one prefix, <x-bs-admin::…> (AdminServiceProvider). The
 * old <x-admin::…> prefix is no longer registered, so a tag written with it renders nothing
 * useful; this catches it at test time instead of on a screen, in every place a Blade view or
 * a Blade string in PHP can live.
 */

it('writes admin components with the bs-admin prefix only', function (): void {
    $violations = [];
    foreach (['app', 'resources', 'themes'] as $dir) {
        if (! File::isDirectory(base_path($dir))) {
            continue;
        }
        foreach (File::allFiles(base_path($dir)) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            foreach (explode("\n", File::get($file->getPathname())) as $i => $line) {
                if (str_contains($line, '<x-admin::') || str_contains($line, '</x-admin::')) {
                    $violations[] = $file->getRelativePathname().':'.($i + 1);
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
