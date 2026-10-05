<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

/*
 * Blade does not compile directives inside a component tag's attributes: in
 * <x-bs-admin::button x-on:click="go(@js($key))"> the text "@js($key)" reaches the browser as is,
 * the click handler is a JavaScript syntax error and the button silently does nothing. Inside a
 * component tag write {{ \Illuminate\Support\Js::from($key) }} instead (what @js compiles to).
 * Compiling every view and finding "@js(" left over catches it at test time, not on a screen.
 */

it('leaves no @js directive uncompiled in any Blade view', function (): void {
    $violations = [];
    foreach (['app', 'resources', 'themes'] as $dir) {
        if (! File::isDirectory(base_path($dir))) {
            continue;
        }
        foreach (File::allFiles(base_path($dir)) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            if (str_contains(Blade::compileString(File::get($file->getPathname())), '@js(')) {
                $violations[] = $dir.'/'.$file->getRelativePathname();
            }
        }
    }

    expect($violations)->toBe([]);
});
