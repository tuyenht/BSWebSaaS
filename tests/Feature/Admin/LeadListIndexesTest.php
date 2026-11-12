<?php

use App\Modules\Admin\Support\Leads\LeadListQuery;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * The leads list ("Khách liên hệ") reads a website's leads newest first, by (created_at, ulid),
 * in the "all" group (every status but spam) or in one status. Each shape has an index in that
 * order. The definitions are pinned here, and the statements LeadListQuery really sends are
 * planned against them as app_user, inside the website's tenancy context (RLS on: only leakproof
 * operators may become index conditions there). The test database is small, so the planner is
 * told to avoid a full table read (`enable_seqscan = off`) and must then pick an index that can
 * serve the statement.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    app(TeamContext::class)->forget();
    $admin = DB::connection('pgsql_admin');
    foreach ([1, 2] as $site) {
        $admin->table('forms')->insert([
            'site_id' => $site, 'ulid' => str_pad("F{$site}", 26, '0', STR_PAD_LEFT), 'key' => 'contact',
            'name' => '{"vi":"Liên hệ"}', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    // 2,000 leads on each of the two websites, one an hour; 1 in 50 new, 1 in 33 spam, the rest
    // read or archived
    $admin->statement(<<<'SQL'
        insert into form_submissions (site_id, ulid, form_id, name, status, created_at, updated_at)
        select s, lpad(s::text || 'x' || g::text, 26, '0'), f.id, 'Khách ' || g,
               case when g % 50 = 0 then 'new' when g % 33 = 0 then 'spam'
                    when g % 2 = 0 then 'read' else 'archived' end,
               timestamptz '2026-12-01 00:00:00+00' - g * interval '1 hour',
               timestamptz '2026-12-01 00:00:00+00' - g * interval '1 hour'
        from generate_series(1, 2) s
        join forms f on f.site_id = s
        cross join generate_series(1, 2000) g
    SQL);
    $admin->statement('analyze form_submissions');
});

function leadList(): LeadListQuery
{
    return new LeadListQuery('', [], '', '', '', app(SiteTime::class));
}

/**
 * The statements $send makes, with their bindings ready for EXPLAIN.
 *
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function leadStatements(Closure $send): array
{
    $sent = [];
    DB::listen(function (QueryExecuted $query) use (&$sent): void {
        if (str_contains($query->sql, 'form_submissions')) {
            $sent[] = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        }
    });
    $send();

    return $sent;
}

/**
 * The index scans Postgres plans for $sql, in the current tenancy context, when a full table read
 * is ruled out: [index name, index condition] each.
 *
 * @param  array<int, mixed>  $bindings
 * @return list<array{0: string, 1: string}>
 */
function leadPlannedScans(string $sql, array $bindings): array
{
    return DB::transaction(function () use ($sql, $bindings): array {
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = json_decode((string) array_values((array) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $bindings))[0], true);
        $scans = [];
        $walk = function (array $node) use (&$walk, &$scans): void {
            if (isset($node['Index Name'])) {
                $scans[] = [(string) $node['Index Name'], (string) ($node['Index Cond'] ?? '')];
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($plan[0]['Plan']);

        return $scans;
    });
}

it('defines each index of the leads list as measured, and drops the one they replace', function (string $index, ?string $definition): void {
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', [$index]);

    if ($definition === null) {
        expect($row)->toBeNull("{$index} should be gone");

        return;
    }
    expect($row)->not->toBeNull("{$index} is missing")
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe("CREATE INDEX {$index} ON public.form_submissions USING btree {$definition}");
})->with([
    'every lead but spam, newest first' => ['form_submissions_live_created_idx', '(site_id, created_at DESC, ulid DESC)'],
    'one status, newest first' => ['form_submissions_status_created_idx', '(site_id, status, created_at DESC, ulid DESC)'],
    'the old (site_id, status) index' => ['form_submissions_site_id_status_index', null],
]);

it('plans the first page of each group on its index', function (string $tab, string $index): void {
    app(Tenancy::class)->enter(1, function () use ($tab, $index): void {
        $sent = leadStatements(fn () => expect(leadList()->rows($tab, null, 26, 100))->toHaveCount(26));

        expect($sent)->toHaveCount(1);
        expect(array_column(leadPlannedScans($sent[0]['sql'], $sent[0]['bindings']), 0))->toBe([$index]);
    });
})->with([
    'Tất cả' => ['all', 'form_submissions_live_created_idx'],
    'Đã đọc' => ['read', 'form_submissions_status_created_idx'],
    'Mới' => ['new', 'form_submissions_status_created_idx'],
]);

it('plans a cursor step deep in the list with the cursor as a range of the index', function (string $tab, int $first, string $index): void {
    app(Tenancy::class)->enter(1, function () use ($tab, $first, $index): void {
        $until = leadList()->nextCursor($tab, null, $first, 0);
        expect($until)->toBeString();

        $sent = leadStatements(fn () => leadList()->nextCursor($tab, $until, 0, 25));
        expect($sent)->toHaveCount(1);
        $scans = leadPlannedScans($sent[0]['sql'], $sent[0]['bindings']);
        expect($scans)->toHaveCount(1)
            ->and($scans[0][0])->toBe($index)
            ->and($scans[0][1])->toContain('ROW(');

        // the rows down to the cursor read the same range from its other end
        $sent = leadStatements(fn () => leadList()->rows($tab, $until, 26, 2000));
        $scans = leadPlannedScans($sent[0]['sql'], $sent[0]['bindings']);
        expect($scans)->toHaveCount(1)
            ->and($scans[0][0])->toBe($index)
            ->and($scans[0][1])->toContain('ROW(');
    });
})->with([
    'Tất cả' => ['all', 1500, 'form_submissions_live_created_idx'],
    'Đã đọc' => ['read', 700, 'form_submissions_status_created_idx'],
]);

it('counts each status on the status index, every count on an index led by the website', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $sent = leadStatements(fn () => leadList()->tabCounts());

        expect($sent)->toHaveCount(1);
        $scans = leadPlannedScans($sent[0]['sql'], $sent[0]['bindings']);
        // five capped counts; "all but spam" may take any index led by site_id (the planner picks
        // the smallest on a small table), each single status must take the status index
        $byStatus = array_filter($scans, fn (array $scan): bool => preg_match('/\(?status\)?(::\w+)? = /', $scan[1]) === 1);
        expect($scans)->toHaveCount(5)
            ->and($byStatus)->toHaveCount(4)
            ->and(array_values(array_unique(array_column($byStatus, 0))))->toBe(['form_submissions_status_created_idx']);
        foreach ($scans as [$index, $cond]) {
            expect($index)->toStartWith('form_submissions_')->and($cond)->toContain('site_id =');
        }
    });
});
