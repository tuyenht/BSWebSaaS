<?php

use Illuminate\Support\Facades\File;

/*
 * A media file is deleted for good only through Media\Actions\PurgeMedia: it takes the file's size
 * off the library's storage totals in the same transaction (MediaStorage). A forceDelete anywhere
 * else in a file that uses the media model would leave the totals counting a file that is gone, so
 * it fails here. The other force deletes in those files are of other models, named below.
 */

const MEDIA_PURGE_CHOKEPOINT = 'app/Modules/Media/Actions/PurgeMedia.php';

/** Files that use the media model and force-delete something else: the file => what it deletes. */
const MEDIA_PURGE_OTHER_MODELS = [
    'app/Modules/Provisioning/ContentPack/Applier.php' => 'menu items of a menu it rebuilds',
    'app/Modules/Provisioning/Actions/PromoteAnVinhProductPostsToCatalog.php' => 'redirects it replaces',
];

test('media rows are force-deleted only in PurgeMedia', function (): void {
    $offenders = [];
    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
        $code = $file->getContents();
        $usesMedia = str_contains($code, 'use App\\Modules\\Media\\Models\\Media;')
            || str_starts_with($path, 'app/Modules/Media/');
        if (! $usesMedia || $path === MEDIA_PURGE_CHOKEPOINT || array_key_exists($path, MEDIA_PURGE_OTHER_MODELS)) {
            continue;
        }
        foreach (preg_split('/\R/', $code) ?: [] as $i => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue; // a comment naming it
            }
            if (preg_match('/->forceDelete(Quietly)?\s*\(|each->forceDelete\b/', $line) === 1) {
                $offenders[] = $path.':'.($i + 1).'  '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('the files allowed another model’s force delete still have one, and the chokepoint exists', function (): void {
    expect(file_exists(base_path(MEDIA_PURGE_CHOKEPOINT)))->toBeTrue();
    foreach (array_keys(MEDIA_PURGE_OTHER_MODELS) as $path) {
        expect(file_get_contents(base_path($path)))->toContain('->forceDelete()');
    }
});
