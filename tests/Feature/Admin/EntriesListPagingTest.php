<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Support\Entries\EntryListFilter;
use App\Modules\Admin\Support\Entries\EntryListQuery;
use App\Modules\Admin\Support\Entries\ListGroup;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The posts and pages list pages in the database. A numbered page is an offset while it stays
 * within the first 10,000 rows; past that, the arrows carry an encrypted cursor naming the row a
 * page follows (or precedes), with its sort value. Every order must then meet each row exactly
 * once, whichever way it is walked: rows that tie on the sort value, rows with no value (no
 * publish date, no title, no author), and a row deleted after its page was shown. Counts stop at
 * 10,001 ("hơn 10.000", with no last page). The browser never sees a row's bigint id.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate entries restart identity cascade');
});

/** Ids far from any small number on the page, so a leaked id cannot pass for something else. */
function pagingIdsFrom(int $start): void
{
    DB::connection('pgsql_admin')->statement("select setval('entries_id_seq', {$start})");
}

function pagingSection(): void
{
    ContentSection::query()->create(['key' => 'posts', 'label' => 'Bài viết', 'entry_type' => 'post', 'permission_prefix' => 'entries', 'sort_order' => 1]);
}

/**
 * 41 posts on website 1 (and a few on website 2, which must never show): many share an edit time
 * or a publish date, some have no publish date, no title or no author.
 *
 * @return array{0: User, 1: User}
 */
function pagingPosts(): array
{
    $a = User::factory()->create(['name' => 'An']);
    $b = User::factory()->create(['name' => 'Bình']);
    foreach (range(1, 41) as $i) {
        $entry = Entry::query()->create([
            'type' => 'post',
            'status' => ['published', 'draft', 'scheduled'][$i % 3],
            'author_id' => [null, $a->id, $b->id][$i % 3 === 0 ? 0 : ($i % 2) + 1],
            'published_at' => $i % 4 === 0 ? null : now()->subDays(intdiv($i, 3)),
            'view_count' => $i % 5,
        ]);
        DB::connection('pgsql_admin')->table('entries')->where('id', $entry->id)->update(['updated_at' => now()->subHours(intdiv($i, 4))]);
        if ($i % 7 !== 0) {
            $entry->translations()->create(['locale' => 'vi', 'title' => ['Ánh', 'anh', 'Bình', 'Đông', 'Em'][$i % 5].' '.intdiv($i, 10), 'slug' => 'p-'.$i, 'status' => 'draft', 'body' => ['blocks' => []]]);
        }
    }

    return [$a, $b];
}

function pagingList(string $tab = 'all'): EntryListQuery
{
    $filter = new EntryListFilter('post', $tab, null, '', [], [], [], [], ['vi'], 'vi', null, null);

    return new EntryListQuery($filter, 1, fn (Builder $rows) => $rows->select(array_map(fn (string $c): string => 'entries.'.$c, EntryListQuery::COLUMNS)));
}

/**
 * The runs of an order, with the authors the author order needs.
 *
 * @param  'asc'|'desc'  $dir
 * @return list<ListGroup>
 */
function pagingGroups(EntryListQuery $list, string $key, string $dir): array
{
    $authors = array_map(fn (array $a): array => $a + ['count' => 1], $list->authors());

    return $list->groups($key, $dir, $authors);
}

/** @return array{g: int, v: string|null, id: int} */
function pagingAnchor(Entry $row): array
{
    return ['g' => (int) $row->getAttribute('__g'), 'v' => $row->getAttribute('__sv'), 'id' => $row->id];
}

it('meets every row once, in one order, by offset and by cursor, in every order of the list', function (string $key, string $dir): void {
    app(Tenancy::class)->enter(1, function () use ($key, $dir): void {
        pagingPosts();
        $list = pagingList();
        $groups = pagingGroups($list, $key, $dir);

        $whole = $list->rows($groups, 0, 1000)->modelKeys();
        expect($whole)->toHaveCount(41)->and(array_unique($whole))->toHaveCount(41);

        $byOffset = [];
        foreach (range(0, 10) as $page) {
            $byOffset = [...$byOffset, ...$list->rows($groups, $page * 4, 4)->modelKeys()];
        }
        expect($byOffset)->toBe($whole);

        $byCursor = [];
        $anchor = null;
        do {
            $rows = $list->rows($groups, 0, 4, $anchor);
            $byCursor = [...$byCursor, ...$rows->modelKeys()];
            $anchor = $rows->isEmpty() ? null : pagingAnchor($rows->last());
        } while ($anchor !== null);
        expect($byCursor)->toBe($whole);
    });
})->with([
    'last edit, newest first' => ['updated', 'desc'],
    'last edit, oldest first' => ['updated', 'asc'],
    'publish date, newest first' => ['pub', 'desc'],
    'publish date, oldest first' => ['pub', 'asc'],
    'name A to Z' => ['name', 'asc'],
    'name Z to A' => ['name', 'desc'],
    'status' => ['status', 'asc'],
    'status, the other way' => ['status', 'desc'],
    'creator' => ['creator', 'asc'],
    'creator, the other way' => ['creator', 'desc'],
    'views' => ['views', 'desc'],
]);

it('orders as the list promises: newest first with undated rows last, ties by the newest record, untitled rows last', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pagingPosts();
        $list = pagingList();

        $rows = $list->rows(pagingGroups($list, 'pub', 'desc'), 0, 1000);
        $expected = $rows->sort(fn (Entry $x, Entry $y): int => [$x->published_at === null, -($x->published_at?->getTimestamp() ?? 0), -$x->id]
            <=> [$y->published_at === null, -($y->published_at?->getTimestamp() ?? 0), -$y->id])->modelKeys();
        expect($rows->modelKeys())->toBe(array_values($expected));

        $rows = $list->rows(pagingGroups($list, 'pub', 'asc'), 0, 1000);
        expect($rows->first()->published_at)->toBeNull()
            ->and($rows->last()->published_at)->not->toBeNull();

        $named = $list->rows(pagingGroups($list, 'name', 'desc'), 0, 1000);
        $titled = Entry::query()->whereHas('translations', fn (Builder $t) => $t->where('locale', 'vi'))->count();
        expect($named->take($titled)->every(fn (Entry $e): bool => $e->translations()->where('locale', 'vi')->exists()))->toBeTrue()
            ->and($named->skip($titled)->every(fn (Entry $e): bool => ! $e->translations()->where('locale', 'vi')->exists()))->toBeTrue();
    });
});

it('keeps paging from a row deleted after its page was shown', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pagingPosts();
        $list = pagingList();
        $groups = pagingGroups($list, 'pub', 'desc');
        $whole = $list->rows($groups, 0, 1000)->modelKeys();
        $page = $list->rows($groups, 0, 6);
        $anchor = pagingAnchor($page->last());

        Entry::query()->whereKey($page->last()->id)->firstOrFail()->delete();

        expect($list->rows($groups, 0, 6, $anchor)->modelKeys())->toBe(array_slice($whole, 6, 6));
    });
});

/** Posts in bulk on website 1, past the 10,000 the list counts to. */
function pagingManyPosts(int $n): void
{
    DB::connection('pgsql_admin')->statement(<<<SQL
        insert into entries (ulid, site_id, type, status, created_at, updated_at)
        select lpad(g::text, 26, '0'), 1, 'post', 'draft', now(), now() - (g / 3) * interval '1 second'
        from generate_series(1, {$n}) g
    SQL);
    DB::connection('pgsql_admin')->statement('analyze entries');
}

it('counts to "hơn 10.000", offers no last page, and turns pages past 10,000 with a cursor both ways', function (): void {
    $user = User::factory()->create();
    pagingIdsFrom(987654320);
    pagingManyPosts(10230);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        pagingSection();
        $list = pagingList();
        $order = $list->rows(pagingGroups($list, 'updated', 'desc'), 10000, 150)->modelKeys();

        $test = Livewire::withQueryParams(['section' => 'posts', 'page' => 201, 'per_page' => 50])
            ->actingAs($user->fresh())->test(Index::class, ['section' => 'posts']);
        $pager = $test->viewData('pager');
        expect($pager['total'])->toBe(__('entries.count_over'))
            ->and($pager['last'])->toBeNull()
            ->and($pager['from'])->toBe(10001)
            ->and($pager['next'])->toHaveKey('token')
            ->and(collect($test->viewData('rows')->items())->map(fn (array $r): int => $r['entry']->id)->all())->toBe(array_slice($order, 0, 50));
        $test->assertSee(__('entries.count_over'));

        $test->call('turnPage', $pager['next']['token']);
        $pager = $test->viewData('pager');
        expect($test->get('page'))->toBe(202)
            ->and(collect($test->viewData('rows')->items())->map(fn (array $r): int => $r['entry']->id)->all())->toBe(array_slice($order, 50, 50));

        $test->call('turnPage', $pager['next']['token']);
        $pager = $test->viewData('pager');
        expect($test->get('page'))->toBe(203)
            ->and(collect($test->viewData('rows')->items())->map(fn (array $r): int => $r['entry']->id)->all())->toBe(array_slice($order, 100, 50))
            ->and($pager['prev'])->toHaveKey('token');

        $test->call('turnPage', $pager['prev']['token']);
        expect($test->get('page'))->toBe(202)
            ->and(collect($test->viewData('rows')->items())->map(fn (array $r): int => $r['entry']->id)->all())->toBe(array_slice($order, 50, 50));

        // neither the page nor its state names a row's id
        expect($test->html())->not->toContain('98765')
            ->and(json_encode($test->snapshot, JSON_THROW_ON_ERROR))->not->toContain('98765');
    });
});

it('stops numbered pages at the 10,000th row and clamps a deeper page number there', function (): void {
    $user = User::factory()->create();
    pagingManyPosts(10230);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        pagingSection();

        $test = Livewire::withQueryParams(['section' => 'posts', 'page' => 999, 'per_page' => 50])
            ->actingAs($user->fresh())->test(Index::class, ['section' => 'posts']);

        expect($test->get('page'))->toBe(201)
            ->and($test->viewData('pager')['from'])->toBe(10001)
            ->and(array_filter($test->viewData('pager')['cells']))->not->toContain(202);
    });
});

it('starts again from page 1 when the view changes or a cursor is forged', function (string $change, mixed $value): void {
    $user = User::factory()->create();
    pagingManyPosts(10230);

    app(Tenancy::class)->enter(1, function () use ($user, $change, $value): void {
        grantRole($user, 'editor', ['entries.view']);
        pagingSection();
        $test = Livewire::withQueryParams(['section' => 'posts', 'page' => 201, 'per_page' => 50])
            ->actingAs($user->fresh())->test(Index::class, ['section' => 'posts']);
        $test->call('turnPage', $test->viewData('pager')['next']['token']);
        expect($test->get('cursor'))->not->toBe('');

        $change === 'forged' ? $test->call('turnPage', $value) : $test->set($change, $value);

        expect($test->get('cursor'))->toBe('')
            ->and($test->get('page'))->toBe(1);
    });
})->with([
    'sort' => ['sortKey', 'pub'],
    'direction' => ['sortDir', 'asc'],
    'tab' => ['tab', 'draft'],
    'page size' => ['perPage', 10],
    'search' => ['search', 'abc'],
    'filter' => ['when', '30'],
    'forged cursor' => ['forged', 'eyJpdiI6Imxvb2tzIGxpa2UgYSBub3RlIn0='],
]);

it('refuses a cursor made for another view of the list', function (): void {
    $user = User::factory()->create();
    pagingManyPosts(10230);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        pagingSection();
        $test = Livewire::withQueryParams(['section' => 'posts', 'page' => 201, 'per_page' => 50])
            ->actingAs($user->fresh())->test(Index::class, ['section' => 'posts']);
        $token = $test->viewData('pager')['next']['token'];

        $other = Livewire::withQueryParams(['section' => 'posts', 'page' => 201, 'per_page' => 50, 'sort' => 'pub'])
            ->actingAs($user->fresh())->test(Index::class, ['section' => 'posts']);
        $other->call('turnPage', $token);

        expect($other->get('page'))->toBe(1)->and($other->get('cursor'))->toBe('');
    });
});

it('lists a large page tree a top page at a time, with the pages below each one', function (): void {
    $user = User::factory()->create();
    // 1,100 top pages, every tenth with two pages below it: past the 1,000 the list keeps in memory
    DB::connection('pgsql_admin')->statement(<<<'SQL'
        insert into entries (ulid, site_id, type, status, position, created_at, updated_at)
        select lpad(g::text, 26, '0'), 1, 'page', 'published', g, now(), now() from generate_series(1, 1100) g
    SQL);
    DB::connection('pgsql_admin')->statement(<<<'SQL'
        insert into entries (ulid, site_id, type, status, parent_id, position, created_at, updated_at)
        select lpad((5000 + g)::text, 26, '0'), 1, 'page', 'draft', ((g + 1) / 2) * 10 - 9, g, now(), now() from generate_series(1, 220) g
    SQL);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);

        $test = Livewire::actingAs($user->fresh())->test(Index::class);
        $rows = collect($test->viewData('rows')->items());

        // 20 top pages; pages 1 and 11 each bring the two below them
        expect($rows->where('depth', 0)->count())->toBe(20)
            ->and($rows->where('depth', 1)->count())->toBe(4)
            ->and($rows->first()['entry']->position)->toBe(1)
            ->and($rows->get(1)['depth'])->toBe(1)
            ->and($test->viewData('pager')['total'])->toBe(1100);
    });

    $count = countQueries(fn () => $this->actingAs($user)->get('http://a.test/admin/content')->assertOk());
    expect($count)->toBeLessThanOrEqual(22);
});

it('reads a large posts list on the flat query budget', function (): void {
    $user = User::factory()->create();
    pagingManyPosts(10230);
    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        pagingSection();
    });

    foreach (['', '&sort=name', '&sort=creator', '&tab=trash', '&q=abc', '&page=201&per_page=50'] as $query) {
        $count = countQueries(fn () => $this->actingAs($user)->get('http://a.test/admin/content?section=posts'.$query)->assertOk());
        expect($count)->toBeLessThanOrEqual(22, "query string {$query}");
    }
});

it('shows a filtered list of pages flat, each row naming the pages above it', function (): void {
    $user = User::factory()->create();
    DB::connection('pgsql_admin')->statement(<<<'SQL'
        insert into entries (ulid, site_id, type, status, position, created_at, updated_at)
        select lpad(g::text, 26, '0'), 1, 'page', case when g = 7 then 'draft' else 'published' end, g, now(), now() from generate_series(1, 1100) g
    SQL);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        $top = Entry::query()->where('position', 7)->firstOrFail();
        $top->translations()->create(['locale' => 'vi', 'title' => 'Dịch vụ', 'slug' => 'dich-vu', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $child = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'parent_id' => $top->id]);
        $child->translations()->create(['locale' => 'vi', 'title' => 'Cơ khí chính xác', 'slug' => 'co-khi', 'status' => 'draft', 'body' => ['blocks' => []]]);

        Livewire::withQueryParams(['tab' => 'draft'])->actingAs($user->fresh())->test(Index::class)
            ->assertSee('Cơ khí chính xác')
            ->assertSee(__('entries.meta_parent', ['path' => 'Dịch vụ']));
    });
});

it('reads only the website it is on, and still refuses a member who may not view posts', function (): void {
    $user = User::factory()->create();
    $outsider = User::factory()->create();
    app(Tenancy::class)->enter(2, function () use ($user): void {
        $other = Entry::query()->create(['type' => 'post', 'status' => 'published', 'author_id' => $user->id]);
        $other->translations()->create(['locale' => 'vi', 'title' => 'Bài của website B', 'slug' => 'b', 'status' => 'draft', 'body' => ['blocks' => []]]);
    });

    app(Tenancy::class)->enter(1, function () use ($user, $outsider): void {
        grantRole($user, 'editor', ['entries.view']);
        grantRole($outsider, 'nobody', []);
        pagingSection();
        pagingPosts();

        $test = Livewire::withQueryParams(['section' => 'posts', 'sort' => 'creator'])->actingAs($user->fresh())->test(Index::class, ['section' => 'posts'])
            ->assertDontSee('Bài của website B');
        expect($test->viewData('pager')['total'])->toBe(41);

        Livewire::actingAs($outsider->fresh())->test(Index::class, ['section' => 'posts'])->assertForbidden();
    });
});
