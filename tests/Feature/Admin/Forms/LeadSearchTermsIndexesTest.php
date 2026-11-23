<?php

use App\Modules\Admin\Support\Leads\LeadListQuery;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * The search of «Khách liên hệ» reads the word table by its keys. The definitions are pinned here,
 * and the statements the list really sends for a search are planned against them as app_user, inside
 * the website's tenancy context (RLS on): only a leakproof condition can become an index condition
 * there, so a search rewritten into LIKE, a trigram or full-text operator, a function on the column
 * or a comparison in another collation falls back to reading rows one by one and fails here. The test
 * database is small, so the planner is told to avoid a full table read (`enable_seqscan = off`).
 */

beforeEach(function (): void {
    seedTenancy();
    $admin = DB::connection('pgsql_admin');
    foreach ([1, 2] as $site) {
        $admin->table('forms')->insert(['ulid' => (string) str()->ulid(), 'site_id' => $site, 'key' => 'contact', 'name' => '{"vi":"Liên hệ"}', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
    // 3,000 leads on each of the two websites; every lead has a common word ("khach"), one word of
    // fifty ("loai7"), and its own ("ma123")
    $admin->statement(<<<'SQL'
        insert into form_submissions (ulid, site_id, form_id, name, status, data, created_at, updated_at)
        select lpad(s::text || '-' || g::text, 26, 'L'), s, f.id, 'Khách ' || g, 'new', '{}'::jsonb,
               timestamptz '2026-12-01 00:00:00+00' - g * interval '1 minute', now()
        from generate_series(1, 2) s join forms f on f.site_id = s and f.key = 'contact', generate_series(1, 3000) g
        SQL);
    $admin->statement('delete from lead_search_terms');
    $admin->statement(<<<'SQL'
        insert into lead_search_terms (site_id, lead_id, term)
        select l.site_id, l.id, w
        from form_submissions l, lateral (values ('khach'), ('loai' || (l.id % 50)), ('ma' || l.id)) as v (w)
        SQL);
    $admin->statement('analyze form_submissions');
    $admin->statement('analyze lead_search_terms');
});

/**
 * Every statement the list sends while it reads its first page and its group counts for $search.
 *
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function leadSearchStatements(string $search, int $gather = LeadListQuery::GATHER): array
{
    $sent = [];
    DB::listen(function (QueryExecuted $query) use (&$sent): void {
        $sent[] = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
    });
    $list = new LeadListQuery($search, [], '', '', '', app(SiteTime::class), hiddenFormIds: [], gather: $gather);
    $list->rows('all', null, 21, 21);
    $list->tabCounts();

    return $sent;
}

/**
 * [index name, index condition] of every index scan in the plan of $sql, and the tables read in full.
 *
 * @param  array<int, mixed>  $bindings
 * @return array{scans: list<array{0: string, 1: string}>, full: list<string>}
 */
function leadSearchPlan(string $sql, array $bindings): array
{
    return DB::transaction(function () use ($sql, $bindings): array {
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = json_decode((string) array_values((array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $bindings))[0], true);
        $scans = [];
        $full = [];
        $walk = function (array $node) use (&$walk, &$scans, &$full): void {
            if (isset($node['Index Name'])) {
                $scans[] = [(string) $node['Index Name'], (string) ($node['Index Cond'] ?? '')];
            } elseif (($node['Node Type'] ?? '') === 'Seq Scan') {
                $full[] = (string) $node['Relation Name'];
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($plan[0]['Plan']);

        return ['scans' => $scans, 'full' => $full];
    });
}

it('defines the keys of the word table as measured', function (string $index, string $definition): void {
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', [$index]);

    expect($row)->not->toBeNull("{$index} is missing")
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe("CREATE {$definition}");
})->with([
    'a word range of one website' => ['lead_search_terms_pkey', 'UNIQUE INDEX lead_search_terms_pkey ON public.lead_search_terms USING btree (site_id, term, lead_id)'],
    'the words of one lead' => ['lead_search_terms_lead_id_index', 'INDEX lead_search_terms_lead_id_index ON public.lead_search_terms USING btree (lead_id)'],
]);

it('keeps its words in the byte order a range needs, and its rows to their own website', function (): void {
    $column = DB::selectOne("select collation_name from information_schema.columns where table_name = 'lead_search_terms' and column_name = 'term'");
    $rls = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'lead_search_terms'");

    expect($column->collation_name)->toBe('C')
        ->and($rls->relrowsecurity)->toBeTrue()
        ->and($rls->relforcerowsecurity)->toBeTrue();
});

it('reads the words of a search through the keys, never the whole table', function (string $search, int $gather, string $shape, array $probe, array $page = []): void {
    app(Tenancy::class)->enter(1, function () use ($search, $gather, $shape, $probe, $page): void {
        expect(DB::selectOne('select current_user as who')->who)->toBe('app_user');
        $statements = leadSearchStatements($search, $gather);
        $words = array_values(array_filter($statements, fn (array $s): bool => str_contains($s['sql'], 'lead_search_terms')));

        // the word counts, the page and the group counts: three statements read the word table
        expect($words)->toHaveCount(3)
            ->and($words[0]['sql'])->toStartWith('select (select count(*) from (select 1 from lead_search_terms')
            ->and($words[1]['sql'])->toContain($shape)
            ->and($words[2]['sql'])->toContain($shape);
        $isRange = fn (array $scan): bool => $scan[0] === 'lead_search_terms_pkey' && str_contains($scan[1], 'term >=') && str_contains($scan[1], 'term <');

        // each word counted is a range of the key
        $counted = leadSearchPlan($words[0]['sql'], $words[0]['bindings']);
        expect($counted['full'])->toBe([])
            ->and(array_values(array_filter($counted['scans'], $isRange)))->toHaveCount(count(explode(' ', $search)));

        // the page and the counts read the words the way their shape says
        foreach (['page' => $words[1], 'count' => $words[2]] as $part => $statement) {
            $plan = leadSearchPlan($statement['sql'], $statement['bindings']);
            $names = array_map(fn (array $scan): string => $scan[0].' ['.$scan[1].']', $plan['scans']);
            expect($plan['full'])->toBe([], $statement['sql']);
            foreach ($probe + ($part === 'page' ? $page : []) as $index => $condition) {
                $hits = array_filter($plan['scans'], fn (array $scan): bool => $scan[0] === $index && str_contains($scan[1], $condition));
                expect($hits)->not->toBe([], "{$index} ({$condition}) in ".implode(', ', $names));
            }
        }
    });
})->with([
    'a rare word: its leads gathered by the key, read by id' => ['loai7', LeadListQuery::GATHER, '= any (array(select t.lead_id', [
        'lead_search_terms_pkey' => 'term >=', 'form_submissions_pkey' => 'id = ANY',
    ]],
    // the other word is checked by its own range of the key (or per lead by lead_id, as the planner prefers): never a row read
    'a rare word with a common one: the other word read by its range too' => ['khach ma7', LeadListQuery::GATHER, '= any (array(select t.lead_id', [
        'lead_search_terms_pkey' => "term >= 'khach'", 'form_submissions_pkey' => 'id = ANY',
    ]],
    'only common words: the list walks newest first and checks each row' => ['khach', 100, 'exists (select 1 from "lead_search_terms" as "w"', [
        'lead_search_terms_lead_id_index' => 'lead_id = form_submissions.id',
    ], ['form_submissions_kept_created_idx' => 'site_id']],
]);

it('walks a website\'s leads for the word check through the list\'s time index, never the whole table', function (): void {
    // 1,500 of website 1's leads have no time, so both passes take more than one chunk
    DB::connection('pgsql_admin')->statement('update form_submissions set created_at = null where id in (select id from form_submissions where site_id = 1 order by id limit 1500)');
    $sent = [];
    DB::listen(function (QueryExecuted $query) use (&$sent): void {
        if (str_starts_with($query->sql, 'select "id", "ulid", "created_at"')) {
            $sent[] = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        }
    });
    $this->artisan('forms:search-terms', ['--site' => 1, '--chunk' => 1000])
        ->expectsOutputToContain('Checked 3000 lead(s) on 1 site(s)')->assertSuccessful();

    // each chunk after the first starts at its cursor inside the index, never above it (a filter there
    // would read every newer lead again on each chunk): what each kind of statement must find in the
    // index condition
    $kinds = [];
    app(Tenancy::class)->enter(1, function () use ($sent, &$kinds): void {
        expect(DB::selectOne('select current_user as who')->who)->toBe('app_user');
        foreach ($sent as $statement) {
            [$kind, $at] = match (true) {
                str_contains($statement['sql'], '(created_at, ulid) <') => ['timed, after a cursor', 'ROW(created_at, ulid) <'],
                str_contains($statement['sql'], '"ulid" <') => ['untimed, after a cursor', 'ulid <'],
                str_contains($statement['sql'], 'is null') => ['untimed, first', 'created_at IS NULL'],
                default => ['timed, first', 'site_id ='],
            };
            $kinds[$kind] = true;
            $plan = leadSearchPlan($statement['sql'], $statement['bindings']);
            $live = array_filter($plan['scans'], fn (array $scan): bool => $scan[0] === 'form_submissions_live_created_idx' && str_contains($scan[1], 'site_id =') && str_contains($scan[1], $at));
            expect($plan['full'])->toBe([], $statement['sql'])
                ->and($live)->not->toBe([], "{$kind}: {$statement['sql']} read ".json_encode($plan['scans']));
        }
    });
    ksort($kinds);
    expect(array_keys($kinds))->toBe(['timed, after a cursor', 'timed, first', 'untimed, after a cursor', 'untimed, first']);
});
