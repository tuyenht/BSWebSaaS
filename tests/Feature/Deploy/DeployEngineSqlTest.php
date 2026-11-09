<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * The prod deploy engine (scripts/deploy/deploy.sh) and its release files read the database through psql
 * with SQL written inside shell scripts. The engine's own suite runs against stubs, so no statement there
 * ever meets a real PostgreSQL; a type error (text || "char") once stopped a prod deploy at its schema
 * snapshot. These tests run those statements on the test database, as the database owner like the
 * engine, and hold the snapshot lines to the patterns the engine itself parses them with.
 */

$engine = static fn (): string => (string) file_get_contents(base_path('scripts/deploy/deploy.sh'));

/**
 * Values of shell variables as bash expands them after sourcing $script (assignments and function
 * definitions only; nothing in a release file runs at load).
 *
 * @return array<string, string>
 */
$shellValues = static function (string $script, string $names): array {
    $result = Process::run(['bash', '-c', 'set +u; source "$1" >/dev/null 2>&1; for v in $2; do printf "%s\0%s\0" "$v" "${!v}"; done', 'probe', $script, $names]);
    expect($result->successful())->toBeTrue();
    $parts = explode("\0", $result->output());
    $values = [];
    for ($i = 0; $i + 1 < count($parts); $i += 2) {
        $values[$parts[$i]] = $parts[$i + 1];
    }

    return $values;
};

$rows = static fn (string $sql): array => array_map(
    static fn (object $row): string => (string) array_values((array) $row)[0],
    DB::connection('pgsql_admin')->select($sql),
);

it('takes the schema snapshot on a real PostgreSQL, every line matching the engine\'s own patterns', function () use ($engine, $shellValues, $rows): void {
    // the snapshot query and the three line patterns, taken from the engine's own assignments
    preg_match('/^CATALOG_SQL=".+?ORDER BY 1"$/ms', $engine(), $sql);
    preg_match('/^SNAP_F=.+?^SNAP_CON=[^\n]+$/ms', $engine(), $snap);
    expect($sql)->toHaveKey(0)->and($snap)->toHaveKey(0);
    $tmp = (string) tempnam(sys_get_temp_dir(), 'snap');
    file_put_contents($tmp, $sql[0]."\n".$snap[0]."\n");
    $v = $shellValues($tmp, 'CATALOG_SQL SNAP_COL SNAP_IDX SNAP_CON');
    unlink($tmp);
    expect($v)->toHaveKeys(['CATALOG_SQL', 'SNAP_COL', 'SNAP_IDX', 'SNAP_CON']);

    $lines = $rows($v['CATALOG_SQL']);
    $odd = array_filter($lines, static function (string $line) use ($v): bool {
        foreach (['SNAP_COL', 'SNAP_IDX', 'SNAP_CON'] as $name) {
            if (preg_match('~'.$v[$name].'~', $line) === 1) {
                return false;
            }
        }

        return true;
    });

    expect($odd)->toBe([]);
    foreach (['col|', 'idx|', 'con|'] as $kind) {
        expect(array_filter($lines, static fn (string $line): bool => str_starts_with($line, $kind)))->not->toBeEmpty();
    }
    expect($lines)->toContain('con|products|products_pkey|p|validated');
});

it('runs every other query the engine sends', function () use ($engine, $rows): void {
    preg_match_all('/\bq "(SELECT [^"$]+)"/', $engine(), $m);
    // the one query built from the release's migration list, with a sample list
    expect($engine())->toContain('q "SELECT count(*) FROM migrations WHERE migration IN ($(printf');
    $queries = [...array_unique($m[1]), "SELECT count(*) FROM migrations WHERE migration IN ('x','y')"];

    expect(count($queries))->toBeGreaterThanOrEqual(6);
    foreach ($queries as $sql) {
        expect($rows($sql))->toHaveCount(1);
    }
});

it('runs every query a release file defines', function () use ($shellValues, $rows): void {
    $files = File::glob(base_path('scripts/deploy/releases/*.sh'));
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        preg_match_all('/^\s*([A-Z_]+_SQL)=/m', (string) file_get_contents($file), $m);
        expect($m[1])->not->toBeEmpty();
        foreach ($shellValues($file, implode(' ', $m[1])) as $name => $sql) {
            expect($sql)->not->toBe('', "{$name} in ".basename($file));
            $rows($sql);   // throws on SQL PostgreSQL rejects
        }
    }
});
