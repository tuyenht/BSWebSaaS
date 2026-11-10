<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The posts and pages list reads one page in the order shown, on an index of its own per order.
 * The definitions are pinned here, and the page query the screen really sends is planned against
 * them as the app plans it in production: as app_user, under RLS, inside the website's tenancy
 * transaction, and as a GENERIC plan (PgBouncer keeps prepared statements, and after a few runs
 * Postgres may stop looking at the values). A partial index matches only a predicate written word
 * for word, so a query that binds the type or the soft-delete test instead of writing it fails
 * here. The test database is small, so a full table read is ruled out (`enable_seqscan = off`)
 * and the planner must then pick an index that serves the query.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $admin = DB::connection('pgsql_admin');
    $admin->statement('truncate entries restart identity cascade');
    // 1,500 posts and 1,200 pages on website 1 (some in the Trash, pages two levels deep), a few on website 2
    $admin->statement(<<<'SQL'
        insert into entries (ulid, site_id, type, status, parent_id, author_id, published_at, position, created_at, updated_at, deleted_at)
        select lpad(g::text, 26, '0'), case when g % 100 = 0 then 2 else 1 end,
               case when g <= 1500 then 'post' else 'page' end,
               (array['draft', 'published', 'scheduled', 'pending'])[1 + g % 4],
               case when g > 1700 and g % 3 <> 0 then 1501 + g % 200 end,
               null, case when g % 5 <> 0 then now() - g * interval '1 hour' end, g % 50,
               now() - g * interval '1 hour', now() - g * interval '1 minute',
               case when g % 37 = 0 then now() end
        from generate_series(1, 2700) g
    SQL);
    $admin->statement(<<<'SQL'
        insert into entry_translations (entry_id, site_id, locale, title, slug, status, body)
        select e.id, e.site_id, 'vi', 'Bài ' || e.id, 'bai-' || e.id, 'draft', '{}' from entries e
    SQL);
    $admin->statement('analyze entries');
    $admin->statement('analyze entry_translations');
});

/**
 * The page query the list sent on its first render with these URL values: the one statement that
 * reads the rows of the page (in the database's order, with a limit).
 *
 * @param  array<string, mixed>  $query
 * @return array{sql: string, bindings: array<int, mixed>}
 */
function entriesPageQuery(string $section, array $query): array
{
    $page = null;
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($user, $section, $query, &$page): void {
        grantRole($user, 'editor', ['entries.view']);
        if ($section === 'posts') {
            ContentSection::query()->create(['key' => 'posts', 'label' => 'Bài viết', 'entry_type' => 'post', 'permission_prefix' => 'entries', 'sort_order' => 1]);
        }
        // half the posts are this member's, so the author order has runs to walk
        DB::connection('pgsql_admin')->statement("update entries set author_id = ? where type = 'post' and id % 2 = 0", [$user->id]);
        DB::listen(function (QueryExecuted $q) use (&$page): void {
            if ($page === null && str_contains($q->sql, '"entries"."ulid"') && str_contains($q->sql, ' order by ') && str_contains($q->sql, ' limit ')) {
                $page = ['sql' => $q->sql, 'bindings' => $q->connection->prepareBindings($q->bindings)];
            }
        });
        Livewire::withQueryParams($query + ['section' => $section])->actingAs($user->fresh())
            ->test(Index::class, ['section' => $section])->assertOk();
    });

    expect($page)->not->toBeNull('The list sent no page query.');

    return $page;
}

/**
 * The indexes a generic plan of $sql reads, planned as app_user on website 1 with a full table
 * read ruled out.
 *
 * @param  array<int, mixed>  $bindings
 * @return list<string>
 */
function entriesGenericPlanIndexes(string $sql, array $bindings): array
{
    $n = 0;
    $numbered = preg_replace_callback('/\?/', function () use (&$n): string {
        return '$'.++$n;
    }, $sql);
    $pdo = DB::getPdo();
    $values = implode(', ', array_map(fn (mixed $v): string => match (true) {
        $v === null => 'null',
        is_bool($v) => $v ? 'true' : 'false',
        is_int($v) => (string) $v,
        default => (string) $pdo->quote((string) $v),
    }, $bindings));

    return app(Tenancy::class)->enter(1, function () use ($numbered, $values): array {
        expect(DB::selectOne('select current_user as u')->u)->toBe('app_user');
        DB::statement('SET LOCAL enable_seqscan = off');
        DB::statement('SET LOCAL plan_cache_mode = force_generic_plan');
        DB::statement("PREPARE entries_page AS {$numbered}");
        try {
            $plan = (array) DB::selectOne('EXPLAIN (FORMAT JSON) EXECUTE entries_page'.($values === '' ? '' : "({$values})"));
        } finally {
            DB::statement('DEALLOCATE entries_page');
        }
        preg_match_all('/"Index Name":\s*"([a-z_]+)"/', (string) reset($plan), $names);

        return array_values(array_unique($names[1]));
    });
}

it('defines each index of the list as the list writes its predicates', function (string $index, string $table, string $definition): void {
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', [$index]);

    expect($row)->not->toBeNull("{$index} is missing")
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe("CREATE INDEX {$index} ON public.{$table} USING btree {$definition}");
})->with([
    'live posts by last edit' => ['entries_post_updated_idx', 'entries', "(site_id, updated_at NULLS FIRST, id) WHERE ((deleted_at IS NULL) AND ((type)::text = 'post'::text))"],
    'live posts by publish date' => ['entries_post_published_idx', 'entries', "(site_id, published_at NULLS FIRST, id) WHERE ((deleted_at IS NULL) AND ((type)::text = 'post'::text))"],
    'live posts within a status' => ['entries_post_status_idx', 'entries', "(site_id, status, updated_at NULLS FIRST, id) WHERE ((deleted_at IS NULL) AND ((type)::text = 'post'::text))"],
    'live posts by author' => ['entries_post_author_idx', 'entries', "(site_id, author_id, updated_at NULLS FIRST, id) WHERE ((deleted_at IS NULL) AND ((type)::text = 'post'::text))"],
    'the Trash' => ['entries_trash_idx', 'entries', '(site_id, type, updated_at NULLS FIRST, id) WHERE (deleted_at IS NOT NULL)'],
    'the top of the page tree' => ['entries_page_top_idx', 'entries', "(site_id, \"position\", id) WHERE ((deleted_at IS NULL) AND ((type)::text = 'page'::text) AND (parent_id IS NULL))"],
    'titles in Vietnamese order' => ['entry_translations_title_icu_idx', 'entry_translations', '(site_id, locale, title COLLATE "vi-VN-x-icu", entry_id)'],
]);

it('plans each order of the posts list on its own index, as a generic plan under RLS', function (array $query, string $index): void {
    ['sql' => $sql, 'bindings' => $bindings] = entriesPageQuery('posts', $query);

    expect(entriesGenericPlanIndexes($sql, $bindings))->toContain($index);
})->with([
    'last edit, newest first' => [[], 'entries_post_updated_idx'],
    'last edit, oldest first' => [['dir' => 'asc'], 'entries_post_updated_idx'],
    'publish date' => [['sort' => 'pub'], 'entries_post_published_idx'],
    'status' => [['sort' => 'status'], 'entries_post_status_idx'],
    'one status group' => [['tab' => 'draft'], 'entries_post_status_idx'],
    'the Trash' => [['tab' => 'trash'], 'entries_trash_idx'],
    'name' => [['sort' => 'name'], 'entry_translations_title_icu_idx'],
    'creator' => [['sort' => 'creator'], 'entries_post_author_idx'],
]);

it('plans the top of a large page tree on the tree index, as a generic plan under RLS', function (): void {
    ['sql' => $sql, 'bindings' => $bindings] = entriesPageQuery('pages', []);

    expect($sql)->toContain('"entries"."parent_id" is null')
        ->and(entriesGenericPlanIndexes($sql, $bindings))->toContain('entries_page_top_idx');
});
