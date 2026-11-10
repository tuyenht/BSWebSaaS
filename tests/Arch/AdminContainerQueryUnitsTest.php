<?php

use Illuminate\Support\Facades\File;

/*
 * Container-query widths in the module views are written in rem, never px. A px width ignores the
 * reader's text size: at a larger root font the row's rem-sized columns outgrow a layout that has
 * not switched yet, and the page scrolls sideways (the audit log at 1366 px with 143 % text). In rem
 * the switch moves with the text, and at the default 16 px it lands exactly where the px one did.
 */
it('writes container-query widths in rem, not px', function (): void {
    $offenders = [];
    foreach (File::allFiles(base_path('app/Modules')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        if (preg_match_all('/@(?:min-|max-)?\[\d+(?:\.\d+)?px\]/', $file->getContents(), $matches) > 0) {
            $offenders[$file->getRelativePathname()] = array_values(array_unique($matches[0]));
        }
    }

    expect($offenders)->toBe([]);
});
