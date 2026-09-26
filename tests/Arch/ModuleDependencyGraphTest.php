<?php

declare(strict_types=1);

/*
 * The module dependency graph must stay ACYCLIC (CLAUDE.md §5–§6, app/Modules/README.md). The
 * pairwise rules in ModuleBoundariesTest only guard the edges someone thought to name — an
 * Admin ↔ Forms cycle once passed them all. This test builds the whole graph from the code (every
 * App\Modules\<X> name outside comments, in PHP and Blade) and fails on ANY cycle, naming it.
 */

/**
 * @return array<string, list<string>> module => the other modules its code names
 */
function moduleDependencyGraph(string $modulesPath): array
{
    $graph = [];

    foreach (scandir($modulesPath) ?: [] as $module) {
        if ($module === '.' || $module === '..' || ! is_dir("{$modulesPath}/{$module}")) {
            continue;
        }

        $references = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator("{$modulesPath}/{$module}", FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            $path = $file->getPathname();
            $source = (string) file_get_contents($path);

            if (str_ends_with($path, '.blade.php')) {
                preg_match_all('/App\\\\Modules\\\\(\w+)\\\\/', (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source), $matches);
                $names = $matches[1];
            } elseif (str_ends_with($path, '.php')) {
                // Tokens, not text: comments and docblocks mention other modules without depending on them.
                $names = [];
                foreach (token_get_all($source) as $token) {
                    if (is_array($token)
                        && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                        && preg_match('/^\\\\?App\\\\Modules\\\\(\w+)(?:\\\\|$)/', $token[1], $match) === 1) {
                        $names[] = $match[1];
                    }
                }
            } else {
                continue;
            }

            foreach ($names as $name) {
                if ($name !== $module) {
                    $references[$name] = true;
                }
            }
        }

        $graph[$module] = array_keys($references);
    }

    return $graph;
}

/**
 * @param  array<string, list<string>>  $graph
 * @return list<list<string>> each cycle as a path that returns to its first module
 */
function moduleDependencyCycles(array $graph): array
{
    $state = [];   // 1 = on the current path, 2 = fully explored
    $path = [];
    $cycles = [];

    $visit = function (string $module) use (&$visit, &$state, &$path, &$cycles, $graph): void {
        $state[$module] = 1;
        $path[] = $module;

        foreach ($graph[$module] ?? [] as $next) {
            if (($state[$next] ?? 0) === 1) {
                $start = (int) array_search($next, $path, true);
                $cycles[] = [...array_slice($path, $start), $next];
            } elseif (($state[$next] ?? 0) === 0) {
                $visit($next);
            }
        }

        array_pop($path);
        $state[$module] = 2;
    };

    foreach (array_keys($graph) as $module) {
        if (($state[$module] ?? 0) === 0) {
            $visit($module);
        }
    }

    return $cycles;
}

it('finds the cycle in a graph that has one, and none in a graph without', function (): void {
    expect(moduleDependencyCycles(['A' => ['B'], 'B' => ['C'], 'C' => ['A'], 'D' => ['A']]))
        ->toBe([['A', 'B', 'C', 'A']])
        ->and(moduleDependencyCycles(['A' => ['B'], 'B' => [], 'C' => ['A', 'B']]))
        ->toBe([]);
});

it('reads the real module edges from the code', function (): void {
    $graph = moduleDependencyGraph(__DIR__.'/../../app/Modules');

    // Guards against a scan that silently sees nothing and so can never find a cycle.
    expect($graph)->toHaveKeys(['Tenancy', 'Cms', 'Admin'])
        ->and($graph['Cms'])->toContain('Tenancy')
        ->and($graph['Admin'])->toContain('Cms')
        ->and($graph['Tenancy'])->not->toContain('Cms');
});

it('keeps the module dependency graph acyclic', function (): void {
    $cycles = array_map(
        fn (array $cycle): string => implode(' → ', $cycle),
        moduleDependencyCycles(moduleDependencyGraph(__DIR__.'/../../app/Modules')),
    );

    expect($cycles)->toBe([]);
});
