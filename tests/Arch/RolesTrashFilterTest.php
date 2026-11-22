<?php

use Illuminate\Support\Facades\File;

/*
 * A custom role in the trash keeps its row in `roles`, and only the Role model's scope hides it.
 * `roles` is platform-wide (no RLS), so a raw query on it — DB::table('roles'), a join on `roles`,
 * or SQL naming it — sees the trash unless it says otherwise. Every such place in app/ names
 * `<alias>.deleted_at` for the alias it gives `roles` (or `roles.deleted_at`) within the statement
 * (the next few lines), so a role in the trash never shows as a
 * filter chip, a count, an owner or a level.
 */

it('keeps raw queries on roles away from the trash', function (): void {
    // each pattern captures the alias the statement gives `roles`, if any
    $patterns = [
        '/(?:table|join|leftJoin|rightJoin|from)\(\s*[\'"]roles(?:\s+as\s+(\w+))?[\'"]/i',
        '/\b(?:join|from)\s+roles\b(?![_.(])(?:\s+(?:as\s+)?(\w+))?/i',
    ];
    $keywords = ['on', 'where', 'join', 'left', 'right', 'inner', 'using', 'group', 'order', 'limit'];
    $window = 6;
    $offenders = [];
    $found = 0;

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php' || str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        $lines = preg_split('/\R/', $file->getContents()) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(?:\*|\/\/|#)/', $line) === 1) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $m) !== 1) {
                    continue;
                }
                $found++;
                $alias = isset($m[1]) && $m[1] !== '' && ! in_array(strtolower($m[1]), $keywords, true) ? $m[1] : 'roles';
                $statement = implode("\n", array_slice($lines, $i, $window));
                if (preg_match('/(?<![\w.])'.preg_quote($alias, '/').'\.deleted_at\b/', $statement) !== 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($i + 1);
                }
            }
        }
    }

    expect($found)->toBeGreaterThan(0)   // the scan still sees the raw joins it guards
        ->and($offenders)->toBe([]);
});
