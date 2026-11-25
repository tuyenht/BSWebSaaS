<?php

use App\Modules\Admin\Support\MediaLibraryDirectory;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * The media library's «Người tạo» filter reads the live files of the chosen people, newest first
 * by (created_at, ulid), a page at a time from a keyset cursor, and counts them. One index serves
 * it: (site_id, created_by, created_at DESC, ulid DESC) on the live files. Its definition is pinned
 * here, and the statements MediaLibraryDirectory really sends are planned against it as app_user,
 * inside the website's tenancy context (RLS on: only leakproof operators may become index
 * conditions there). The test database is small, so the planner is told to avoid a full table read
 * (`enable_seqscan = off`) and must then pick an index that can serve the statement.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    app(TeamContext::class)->forget();
    // 2,000 files on each of the two websites, one an hour, from five uploaders (user 1 sent 1 in
    // 50, the others the rest), 1 in 40 in the Trash
    DB::connection('pgsql_admin')->statement(<<<'SQL'
        insert into media (site_id, ulid, collection, slug, hash, storage_key, original_filename, mime, size, created_by, created_at, updated_at, deleted_at)
        select s, lpad(s::text || 'm' || g::text, 26, '0'), 'library', 'f-' || g, md5(s::text || '-' || g::text),
               's' || s || '/library/f-' || g || '.png', 'f-' || g || '.png', 'image/png', 2048,
               case when g % 50 = 0 then 1 else 2 + g % 4 end,
               timestamptz '2026-12-01 00:00:00+00' - g * interval '1 hour',
               timestamptz '2026-12-01 00:00:00+00' - g * interval '1 hour',
               case when g % 40 = 0 then timestamptz '2026-12-02 00:00:00+00' end
        from generate_series(1, 2) s
        cross join generate_series(1, 2000) g
    SQL);
    DB::connection('pgsql_admin')->statement('analyze media');
});

/**
 * The live files of these uploaders, as the library filters them with nothing else chosen.
 *
 * @param  list<int>  $by
 * @return array{folders: null, unfiled: false, trash: false, search: string, kinds: list<string>, formats: list<string>, uses: list<string>, by: list<int>}
 */
function mciFilter(array $by): array
{
    return ['folders' => null, 'unfiled' => false, 'trash' => false, 'search' => '', 'kinds' => [], 'formats' => [], 'uses' => [], 'by' => $by];
}

/**
 * The media statements $send makes, with their bindings ready for EXPLAIN.
 *
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function mciStatements(Closure $send): array
{
    $sent = [];
    DB::listen(function (QueryExecuted $query) use (&$sent): void {
        if (str_contains($query->sql, '"media"')) {
            $sent[] = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        }
    });
    $send();

    return $sent;
}

/**
 * The plan's nodes for $sql in the current tenancy context with a full table read ruled out:
 * [node type, index name, index condition] each, top down.
 *
 * @param  array<int, mixed>  $bindings
 * @return list<array{0: string, 1: string, 2: string}>
 */
function mciNodes(string $sql, array $bindings): array
{
    $plan = DB::transaction(function () use ($sql, $bindings): array {
        DB::statement('SET LOCAL enable_seqscan = off');

        return json_decode((string) array_values((array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $bindings))[0], true)[0]['Plan'];
    });
    $nodes = [];
    $walk = function (array $node) use (&$walk, &$nodes): void {
        $nodes[] = [(string) $node['Node Type'], (string) ($node['Index Name'] ?? ''), (string) ($node['Index Cond'] ?? '')];
        foreach ($node['Plans'] ?? [] as $child) {
            $walk($child);
        }
    };
    $walk($plan);

    return $nodes;
}

it('defines the «Người tạo» index on the live files in the list’s own order', function (): void {
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', ['media_site_creator_created_idx']);

    expect($row)->not->toBeNull()
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe('CREATE INDEX media_site_creator_created_idx ON public.media USING btree (site_id, created_by, created_at DESC, ulid DESC) WHERE (deleted_at IS NULL)');
});

it('plans the first page, a cursor step and the count of one uploader’s files on that index, with no sort', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $directory = app(MediaLibraryDirectory::class);
        $query = $directory->filtered(mciFilter([1]));

        $first = mciStatements(fn () => $directory->page($query, 'new', 24, '', ''));
        expect($first)->toHaveCount(1);
        $nodes = mciNodes($first[0]['sql'], $first[0]['bindings']);
        expect(array_column($nodes, 1))->toContain('media_site_creator_created_idx')
            ->and(array_column($nodes, 0))->not->toContain('Sort')->not->toContain('Incremental Sort');

        $older = $directory->page($query, 'new', 24, '', '')['older'];
        expect($older)->toBeString();
        $step = mciStatements(fn () => $directory->page($query, 'new', 24, (string) $older, ''));
        // the cursor's own row, then the page after it
        expect($step)->toHaveCount(2);
        $nodes = mciNodes($step[1]['sql'], $step[1]['bindings']);
        $scan = array_values(array_filter($nodes, fn (array $n): bool => $n[1] === 'media_site_creator_created_idx'));
        expect($scan)->not->toBeEmpty()
            ->and($scan[0][2])->toContain('created_by')->toContain('created_at')->toContain('ulid')
            ->and(array_column($nodes, 0))->not->toContain('Sort')->not->toContain('Incremental Sort');

        $count = mciStatements(fn () => $directory->totals($query));
        expect(array_column(mciNodes($count[0]['sql'], $count[0]['bindings']), 1))->toContain('media_site_creator_created_idx');
    });
});

it('keeps each person’s range of the index inside the website', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $directory = app(MediaLibraryDirectory::class);
        $rows = $directory->page($directory->filtered(mciFilter([1, 3])), 'new', 96, '', '')['rows'];

        // site 1 holds 30 live files of user 1 (2000 / 50, less the 10 in the Trash) and 500 of user 3
        expect($rows)->toHaveCount(96)
            ->and($directory->totals($directory->filtered(mciFilter([1])))['n'])->toBe(30)
            ->and($rows->every(fn ($m): bool => str_starts_with((string) $m->storage_key, 's1/')))->toBeTrue();
    });
});
