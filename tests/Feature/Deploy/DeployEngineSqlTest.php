<?php

use Illuminate\Support\Facades\DB;

/*
 * The prod deploy engine (scripts/deploy/deploy.sh) reads the database through psql with SQL written
 * inside the shell script. Its own test suite runs against stubs, so no statement there ever meets a
 * real PostgreSQL; a type error (text || "char") once stopped a prod deploy at its schema snapshot.
 * These tests run the engine's statements on the test database, as the database owner like the engine,
 * and hold the snapshot lines to the shapes the engine parses.
 */

$engine = static fn (): string => (string) file_get_contents(base_path('scripts/deploy/deploy.sh'));

$catalogSql = static function () use ($engine): string {
    preg_match('/^CATALOG_SQL="(.+?ORDER BY 1)"$/ms', $engine(), $m);
    expect($m)->toHaveKey(1);

    return $m[1];
};

it('takes the schema snapshot on a real PostgreSQL, every line in a shape the engine parses', function () use ($catalogSql): void {
    $lines = array_map(
        static fn (object $row): string => (string) array_values((array) $row)[0],
        DB::connection('pgsql_admin')->select($catalogSql()),
    );

    $field = '[^|[:cntrl:]]+';
    $shapes = [
        "/^col\\|{$field}\\|{$field}\\|{$field}\\|(null|notnull)$/",
        "/^idx\\|{$field}\\|{$field}\\|(valid|INVALID)$/",
        "/^con\\|{$field}\\|{$field}\\|[a-z]\\|(validated|NOT-VALID)$/",
    ];
    $odd = array_filter($lines, static function (string $line) use ($shapes): bool {
        foreach ($shapes as $shape) {
            if (preg_match($shape, $line) === 1) {
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

it('runs every fixed query the engine sends', function () use ($engine): void {
    preg_match_all('/\bq "(SELECT [^"$]+)"/', $engine(), $m);

    expect($m[1])->not->toBeEmpty();
    foreach (array_unique($m[1]) as $sql) {
        expect(DB::connection('pgsql_admin')->select($sql))->toHaveCount(1);
    }
});
