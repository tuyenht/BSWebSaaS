<?php

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Registry\NotificationKinds;
use App\Modules\Notifications\Support\NotificationFeed;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The Notifications page ("Thông báo") counts its six tabs on every render and lists one tab newest
 * first by id: every notice, the unread ones, or one group of kinds. Each shape has an index led by
 * (site_id, user_id). The definitions are pinned here, and the statements NotificationFeed really
 * sends are planned against them as app_user, inside the website's tenancy context (RLS on: only
 * leakproof operators may become index conditions there). The test database is small, so the
 * planner is told to avoid a full table read (`enable_seqscan = off`) and must then pick an index
 * that can serve the statement.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    app(TeamContext::class)->forget();
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate site_notifications restart identity cascade');
});

/** Notices to plan against, analyzed; returns the reader they are about. */
function seedNotificationPage(): User
{
    $admin = DB::connection('pgsql_admin');
    $reader = User::factory()->create();
    $others = User::factory()->count(5)->create()->modelKeys();
    // One reader's notices are a sliver of the table, as on a large one (otherwise the planner
    // rightly reads the whole small table in id order). On website 1: 600 for the reader and 600 for
    // a colleague, oldest first: mostly leads, every 50th a workflow notice, 4 security and 2 system
    // ones among them; the oldest 150 unread. Also on website 1, 30,000 for each of four others with
    // the long-tail mix (80 % leads, 15 % workflow, 3 % security, 2 % system; 1 in 10 unread). On
    // website 2: 100 for the reader.
    $admin->statement(<<<'SQL'
        insert into site_notifications (ulid, site_id, user_id, kind, data, read_at, created_at)
        select lpad(v.s::text || 'x' || v.u::text || 'x' || g::text, 26, '0'), v.s, v.u,
               case when v.n > 1000 then case when g % 100 < 80 then 'lead.new' when g % 100 < 95 then 'workflow.returned'
                                              when g % 100 < 98 then 'security.support_sign_in' else 'system.storage_near_limit' end
                    when g in (10, 110, 230, 470) then 'security.new_device_login'
                    when g in (130, 370) then 'system.domain_problem'
                    when g % 50 = 0 then 'workflow.assigned' else 'lead.new' end,
               '{}',
               case when (v.n <= 1000 and g <= 150) or (v.n > 1000 and g % 10 = 0) then null
                    else timestamptz '2026-11-01 00:00:00+00' end,
               timestamptz '2026-08-01 00:00:00+00' + g * interval '5 minutes'
        from (values (1, ?::bigint, 600), (1, ?::bigint, 600), (2, ?::bigint, 100),
                     (1, ?::bigint, 30000), (1, ?::bigint, 30000), (1, ?::bigint, 30000), (1, ?::bigint, 30000)) v(s, u, n)
        cross join lateral generate_series(1, v.n) g
        order by g, v.s, v.u
    SQL, [$reader->id, $others[0], $reader->id, $others[1], $others[2], $others[3], $others[4]]);
    $admin->statement('analyze site_notifications');

    return $reader;
}

/** The tabs as the page asks for them: [group, unread only]. */
function notificationTabs(): array
{
    $tabs = [];
    foreach (['tat-ca' => [null, false], 'chua-doc' => [null, true], 'duyet-bai' => ['workflow', false],
        'khach-lien-he' => ['lead', false], 'bao-mat' => ['security', false], 'he-thong' => ['system', false]] as $key => [$group, $unread]) {
        $tabs[$key] = ['kinds' => $group !== null ? NotificationKinds::ofGroup($group) : null, 'unread' => $unread];
    }

    return $tabs;
}

/**
 * The statements $send makes, with their bindings ready for EXPLAIN.
 *
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function notificationStatements(Closure $send): array
{
    $sent = [];
    DB::listen(function (QueryExecuted $query) use (&$sent): void {
        if (str_contains($query->sql, 'site_notifications')) {
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
function notificationPlannedScans(string $sql, array $bindings): array
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

/** The scans of $index only (a cursor's own lookup by ulid takes the ulid index). */
function notificationScansOn(array $scans, string $index): array
{
    return array_values(array_filter($scans, fn (array $scan): bool => $scan[0] === $index));
}

dataset('notification page indexes', [
    'every notice, newest first, with the search line' => ['site_notifications_feed_covering', '(site_id, user_id, id DESC) INCLUDE (search_text)'],
    'the unread ones, newest first' => ['site_notifications_unread_feed', '(site_id, user_id, id DESC) WHERE (read_at IS NULL)'],
    'one group, newest first, with the search line' => ['site_notifications_kind_feed_covering', '(site_id, user_id, kind, id DESC) INCLUDE (search_text)'],
    'the old unread index without id' => ['site_notifications_unread', null],
    'the old feed without the search line' => ['site_notifications_feed', null],
    'the old group index without the search line' => ['site_notifications_kind_feed', null],
]);

function expectNotificationIndex(string $index, ?string $definition): void
{
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', [$index]);

    if ($definition === null) {
        expect($row)->toBeNull("{$index} should be gone");

        return;
    }
    expect($row)->not->toBeNull("{$index} is missing")
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe("CREATE INDEX {$index} ON public.site_notifications USING btree {$definition}");
}

it('defines each index of the Notifications page as measured, and drops the one it replaces', function (string $index, ?string $definition): void {
    expectNotificationIndex($index, $definition);
})->with('notification page indexes');

it('puts the old unread index back on rollback and the new ones again on re-run', function (): void {
    seedNotificationPage();
    // the later migration that replaced the feed and the group index is rolled back first, as
    // migrate:rollback would, and run again last
    $covering = require database_path('migrations/2071_01_01_000000_add_search_text_to_notification_feed_indexes.php');
    $migration = require database_path('migrations/2061_01_01_000000_add_notification_page_indexes.php');
    $admin = DB::connection('pgsql_admin');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $covering->down();
        $migration->down();
        expectNotificationIndex('site_notifications_unread', '(site_id, user_id) WHERE (read_at IS NULL)');
        expectNotificationIndex('site_notifications_unread_feed', null);
        expectNotificationIndex('site_notifications_kind_feed', null);
        expectNotificationIndex('site_notifications_feed', '(site_id, user_id, id DESC)');
    } finally {
        $migration->up();
        $covering->up();
        DB::setDefaultConnection($previous);
    }
    expectNotificationIndex('site_notifications_unread', null);
    expectNotificationIndex('site_notifications_unread_feed', '(site_id, user_id, id DESC) WHERE (read_at IS NULL)');
    expectNotificationIndex('site_notifications_kind_feed', null);
    expectNotificationIndex('site_notifications_kind_feed_covering', '(site_id, user_id, kind, id DESC) INCLUDE (search_text)');
    expect($admin->table('site_notifications')->count())->toBe(121300);
})->skip(getenv('CI') !== 'true', 'drops and re-adds indexes: runs on CI\'s own database only');

it('builds the two indexes with the search line before it drops the ones they replace, back on rollback, re-runnable', function (): void {
    seedNotificationPage();
    $migration = require database_path('migrations/2071_01_01_000000_add_search_text_to_notification_feed_indexes.php');
    $admin = DB::connection('pgsql_admin');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        expectNotificationIndex('site_notifications_feed', '(site_id, user_id, id DESC)');
        expectNotificationIndex('site_notifications_kind_feed', '(site_id, user_id, kind, id DESC)');
        expectNotificationIndex('site_notifications_feed_covering', null);
        expectNotificationIndex('site_notifications_kind_feed_covering', null);
        $migration->down(); // a repeated step is harmless

        // an interrupted CONCURRENTLY build leaves its index INVALID: the next run drops and builds it
        // again, and the old index stays until then
        // (a unique build that meets duplicates fails the same way, leaving the name taken)
        expect(fn () => $admin->statement('CREATE UNIQUE INDEX CONCURRENTLY site_notifications_feed_covering ON site_notifications (site_id)'))
            ->toThrow(QueryException::class);
        expect($admin->selectOne("SELECT indisvalid AS v FROM pg_index WHERE indexrelid = to_regclass('site_notifications_feed_covering')")->v)->toBeFalse();
    } finally {
        $migration->up();
        $migration->up();
        DB::setDefaultConnection($previous);
    }
    expectNotificationIndex('site_notifications_feed_covering', '(site_id, user_id, id DESC) INCLUDE (search_text)');
    expectNotificationIndex('site_notifications_kind_feed_covering', '(site_id, user_id, kind, id DESC) INCLUDE (search_text)');
    expectNotificationIndex('site_notifications_feed', null);
    expectNotificationIndex('site_notifications_kind_feed', null);
    expect($admin->table('site_notifications')->count())->toBe(121300);
})->skip(getenv('CI') !== 'true', 'drops and re-adds indexes: runs on CI\'s own database only');

/** site_notifications' own storage parameters (reloptions), sorted. */
function notificationTableOptions(): array
{
    $options = DB::selectOne("select coalesce(reloptions, '{}') as o from pg_class where oid = 'public.site_notifications'::regclass")->o;
    $list = array_values(array_filter(explode(',', trim((string) $options, '{}'))));
    sort($list);

    return $list;
}

it('vacuums the notices table once 1 % of it changed, so search speed comes back soon after a mass rewrite', function (): void {
    expect(notificationTableOptions())->toBe(['autovacuum_vacuum_insert_scale_factor=0.01', 'autovacuum_vacuum_scale_factor=0.01']);
});

it('puts the server default vacuum thresholds back on rollback and the 1 % ones again on re-run', function (): void {
    $migration = require database_path('migrations/2072_01_01_000000_set_notification_autovacuum_thresholds.php');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        expect(notificationTableOptions())->toBe([]);
        $migration->down(); // a repeated step is harmless
    } finally {
        $migration->up();
        $migration->up();
        DB::setDefaultConnection($previous);
    }
    expect(notificationTableOptions())->toBe(['autovacuum_vacuum_insert_scale_factor=0.01', 'autovacuum_vacuum_scale_factor=0.01']);
})->skip(getenv('CI') !== 'true', 'changes the table\'s storage parameters: runs on CI\'s own database only');

it('counts every tab on the index that serves it, each read led by the website and the reader', function (): void {
    $this->reader = seedNotificationPage();
    app(Tenancy::class)->enter(1, function (): void {
        $sent = notificationStatements(fn () => expect(app(NotificationFeed::class)->countsUpTo($this->reader, 100, notificationTabs()))
            ->toBe(['tat-ca' => 100, 'chua-doc' => 100, 'duyet-bai' => 12, 'khach-lien-he' => 100, 'bao-mat' => 4, 'he-thong' => 2]));

        expect($sent)->toHaveCount(1);
        $scans = notificationPlannedScans($sent[0]['sql'], $sent[0]['bindings']);
        // six capped counts: "Tất cả" may take any index led by (site_id, user_id) (the planner
        // picks the smallest on a small table); the unread count must take the unread index and
        // each group count the kind index, with the group's kinds in the index condition
        $byKind = array_values(array_filter($scans, fn (array $scan): bool => preg_match('/kind\)?(::\w+)? = /', $scan[1]) === 1));
        expect($scans)->toHaveCount(6)
            ->and(notificationScansOn($scans, 'site_notifications_unread_feed'))->toHaveCount(1)
            ->and($byKind)->toHaveCount(4, 'four group counts with their kinds as an index condition')
            ->and(array_values(array_unique(array_column($byKind, 0))))->toBe(['site_notifications_kind_feed_covering']);
        foreach ($scans as [$index, $cond]) {
            expect($index)->toStartWith('site_notifications_')->and($cond)->toContain('site_id =')->and($cond)->toContain('user_id =');
        }
    });
});

it('lists the unread tab, its cursor steps and the bell on the unread index', function (): void {
    $this->reader = seedNotificationPage();
    app(Tenancy::class)->enter(1, function (): void {
        $feed = app(NotificationFeed::class);
        $first = $feed->page($this->reader, null, true, 8);
        expect($first)->toHaveCount(8)->and($first->first()->read_at)->toBeNull();
        $deep = $feed->page($this->reader, null, true, 100)->last();

        $shapes = [
            'first page' => [fn () => $feed->page($this->reader, null, true, 8), null],
            'is there more' => [fn () => $feed->page($this->reader, null, true, 1, after: $first->last()->ulid), 'id <'],
            'drawn again down to the cursor' => [fn () => $feed->page($this->reader, null, true, 1000, through: $deep->ulid), 'id >='],
            'the bell' => [fn () => $feed->unreadCountUpTo($this->reader, 100), null],
        ];
        foreach ($shapes as $shape => [$send, $cursor]) {
            $sent = notificationStatements($send);
            expect($sent)->toHaveCount(1, $shape);
            $scans = notificationScansOn(notificationPlannedScans($sent[0]['sql'], $sent[0]['bindings']), 'site_notifications_unread_feed');
            expect($scans)->toHaveCount(1, $shape);
            if ($cursor !== null) {
                expect($scans[0][1])->toContain($cursor);
            }
        }
    });
});

it('reads the newest unread notices for "Đánh dấu đã đọc tất cả" on the unread index', function (): void {
    $this->reader = seedNotificationPage();
    app(Tenancy::class)->enter(1, function (): void {
        $sent = notificationStatements(fn () => app(NotificationFeed::class)->markAllReadForUndo($this->reader, '2026-11-14 10:00:00'));

        // the select of up to 201 ulids comes first, then the update
        expect($sent)->toHaveCount(2)->and($sent[0]['sql'])->toStartWith('select');
        expect(notificationScansOn(notificationPlannedScans($sent[0]['sql'], $sent[0]['bindings']), 'site_notifications_unread_feed'))->toHaveCount(1);
    });
});

it('lists one group on an index led by the reader, a rare group on the kind index, its cursor step too', function (string $group, int $first, array $served): void {
    $this->reader = seedNotificationPage();
    app(Tenancy::class)->enter(1, function () use ($group, $first, $served): void {
        $feed = app(NotificationFeed::class);
        $kinds = NotificationKinds::ofGroup($group);
        $page = $feed->page($this->reader, $kinds, false, 8);
        expect($page)->toHaveCount($first);

        foreach ([fn () => $feed->page($this->reader, $kinds, false, 8), fn () => $feed->page($this->reader, $kinds, false, 1, after: $page->last()->ulid)] as $send) {
            $sent = notificationStatements($send);
            expect($sent)->toHaveCount(1);
            $scans = array_values(array_filter(notificationPlannedScans($sent[0]['sql'], $sent[0]['bindings']), fn (array $scan): bool => $scan[0] !== 'site_notifications_ulid_unique'));
            expect($scans)->toHaveCount(1)->and($served)->toContain($scans[0][0]);
        }
    });
})->with([
    // a group that is rare across the table must read only its own rows: walking the feed would
    // read the reader's whole slice for four notices
    'Bảo mật (several kinds, rare)' => ['security', 4, ['site_notifications_kind_feed_covering']],
    // a group that is common across the table (here 15 % workflow, 80 % leads) is found within the
    // feed's newest few rows, so the planner may take either index
    'Duyệt bài (several kinds, common)' => ['workflow', 8, ['site_notifications_kind_feed_covering', 'site_notifications_feed_covering']],
    'Khách liên hệ (one kind, most notices)' => ['lead', 8, ['site_notifications_kind_feed_covering', 'site_notifications_feed_covering']],
]);
