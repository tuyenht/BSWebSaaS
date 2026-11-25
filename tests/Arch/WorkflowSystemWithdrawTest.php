<?php

use App\Modules\Cms\Actions\UnpublishExpiredEntry;
use App\Modules\Workflow\Services\WorkflowEngine;

/*
 * WorkflowEngine::withdrawAsSystem moves a governed post off the website with no actor and no permission
 * check: only the end-time sweep may call it. Any other caller would be a way round the flow's rights.
 */
it('lets only the end-time sweep call the engine\'s system withdraw', function (): void {
    $allowed = array_map(
        fn (string $class): string => (string) (new ReflectionClass($class))->getFileName(),
        [WorkflowEngine::class, UnpublishExpiredEntry::class],
    );
    $callers = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'withdrawAsSystem(')) {
            $callers[] = $file->getPathname();
        }
    }
    sort($callers);
    sort($allowed);

    expect($callers)->toBe($allowed);
});
