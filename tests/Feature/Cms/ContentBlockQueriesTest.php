<?php

use App\Modules\Cms\Models\BlockUsage;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Queries\ContentBlockList;
use App\Modules\Cms\Queries\ContentBlockUsages;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\LayoutSlots;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * The block library's reads: the list in keyset pages (newest change first, filtered by status and
 * region), the status × region counts for the tabs and chips, the languages each block has, "Dùng ở
 * N nơi" for a whole page in one read, and the list of places with their names. A place is one
 * owner whatever its languages, and a page or menu item waiting in the Trash is not counted. Each
 * read costs a fixed number of queries however many blocks or places there are, and sees only the
 * current website.
 */

beforeEach(fn () => seedTenancy());

/** Blocks of site 1 named b1…b$count, b$count changed last (one minute apart). */
function listedBlocks(int $count): void
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $at = Carbon::parse('2026-11-01 08:00:00')->addMinutes($i);
        $rows[] = [
            'ulid' => sprintf('01JLISTED%017d', $i), 'site_id' => 1, 'key' => "b{$i}", 'name' => "Khối {$i}",
            'region' => ContentBlock::REGIONS[$i % 5], 'status' => $i % 3 === 0 ? 'published' : 'draft',
            'created_at' => $at, 'updated_at' => $at,
        ];
    }
    DB::connection('pgsql_admin')->table('content_blocks')->insert($rows);
}

it('lists blocks newest change first in keyset pages, ties broken by id', function (): void {
    listedBlocks(7);
    // two blocks changed at the same instant: the later id comes first
    DB::connection('pgsql_admin')->table('content_blocks')->whereIn('key', ['b6', 'b7'])->update(['updated_at' => '2026-11-02 08:00:00.123456+00']);

    [$keys, $cursors] = app(Tenancy::class)->enter(1, function (): array {
        $list = app(ContentBlockList::class);
        $pages = [];
        $cursors = [];
        $after = null;
        do {
            $page = $list->page(after: $after, limit: 3);
            $pages[] = array_map(fn (ContentBlock $block): string => $block->key, $page['rows']);
            $after = $page['next'];
            $cursors[] = $after;
        } while ($after !== null);

        return [$pages, $cursors];
    });

    // the cursor names the last row by its time and ulid; no bigint id leaves the server
    expect($keys)->toBe([['b7', 'b6', 'b5'], ['b4', 'b3', 'b2'], ['b1']])
        ->and(array_keys($cursors[0] ?? []))->toBe(['at', 'ulid'])
        ->and($cursors[0]['ulid'] ?? null)->toBe(sprintf('01JLISTED%017d', 5))
        ->and($cursors[1]['ulid'] ?? null)->toBe(sprintf('01JLISTED%017d', 2))
        ->and($cursors[2])->toBeNull();
});

it('goes on after an anchor deleted between pages, repeating a same-instant block rather than skipping one', function (): void {
    listedBlocks(5);
    DB::connection('pgsql_admin')->table('content_blocks')->whereIn('key', ['b3', 'b4'])->update(['updated_at' => '2026-11-02 08:00:00+00']);

    [$first, $second] = app(Tenancy::class)->enter(1, function (): array {
        $list = app(ContentBlockList::class);
        $first = $list->page(limit: 2); // b4, b3 (same instant, higher id first)
        ContentBlock::query()->where('key', 'b3')->delete();
        $second = $list->page(after: $first['next'], limit: 3);

        return [
            array_map(fn (ContentBlock $block): string => $block->key, $first['rows']),
            array_map(fn (ContentBlock $block): string => $block->key, $second['rows']),
        ];
    });

    expect($first)->toBe(['b4', 'b3'])
        ->and($second)->toBe(['b4', 'b5', 'b2']);
});

it('filters the list by status and region', function (): void {
    listedBlocks(15);

    app(Tenancy::class)->enter(1, function (): void {
        $list = app(ContentBlockList::class);
        $published = array_map(fn (ContentBlock $b): string => $b->key, $list->page(status: 'published')['rows']);
        $footer = array_map(fn (ContentBlock $b): string => $b->region, $list->page(region: 'footer')['rows']);

        expect($published)->toBe(['b15', 'b12', 'b9', 'b6', 'b3'])
            ->and($footer)->toHaveCount(3)->each->toBe('footer')
            ->and($list->page(status: 'published', region: 'footer')['rows'])->toHaveCount(1);
    });
});

it('counts the blocks of each status and region in one query, every pair present', function (): void {
    listedBlocks(15);

    [$counts, $queries] = app(Tenancy::class)->enter(1, function (): array {
        $counts = null;
        $queries = countQueries(function () use (&$counts): void {
            $counts = app(ContentBlockList::class)->counts();
        });

        return [$counts, $queries];
    });

    expect($queries)->toBe(1)
        ->and(array_keys($counts))->toBe(['draft', 'published'])
        ->and(array_keys($counts['draft']))->toBe(ContentBlock::REGIONS)
        ->and(array_sum($counts['published']))->toBe(5)
        ->and(array_sum($counts['draft']))->toBe(10)
        ->and($counts['published']['footer'])->toBe(1)
        ->and(app(Tenancy::class)->enter(2, fn () => array_sum(array_map('array_sum', app(ContentBlockList::class)->counts()))))->toBe(0);
});

it('reads the languages of a page of blocks in one query', function (): void {
    listedBlocks(3);
    DB::connection('pgsql_admin')->table('content_block_translations')->insert([
        ['site_id' => 1, 'content_block_id' => 1, 'locale' => 'vi', 'body' => '{"blocks":[]}'],
        ['site_id' => 1, 'content_block_id' => 1, 'locale' => 'en', 'body' => '{"blocks":[]}'],
        ['site_id' => 1, 'content_block_id' => 3, 'locale' => 'vi', 'body' => '{"blocks":[]}'],
    ]);

    app(Tenancy::class)->enter(1, function (): void {
        $locales = null;
        $queries = countQueries(function () use (&$locales): void {
            $locales = app(ContentBlockList::class)->locales([1, 2, 3]);
        });

        expect($queries)->toBe(1)
            ->and($locales)->toBe([1 => ['en', 'vi'], 3 => ['vi']]);
    });
});

/**
 * Site 1: three blocks and owners using them. Block 1 is in a page (two languages: one place), a
 * post that is in the Trash, a category's slot, the website's default slot and a menu item; block 2
 * is in the same page and in a menu item that is in the Trash; block 3 is used nowhere.
 *
 * @return array{blocks: list<ContentBlock>, page: Entry, category: Taxonomy, item: MenuItem}
 */
function usedBlocks(): array
{
    return app(Tenancy::class)->enter(1, function (): array {
        $blocks = [];
        foreach (['footer', 'header', 'sidebar'] as $key) {
            $blocks[] = ContentBlock::query()->create(['key' => $key, 'name' => ucfirst($key)]);
        }
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);
        EntryTranslation::query()->create(['entry_id' => $page->id, 'locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'body' => ['blocks' => []]]);
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
        $post->delete(); // in the Trash
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        TaxonomyTranslation::query()->create(['taxonomy_id' => $category->id, 'locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        $setting = SiteSetting::query()->create(['group_name' => LayoutSlots::SETTING_GROUP, 'key' => LayoutSlots::SETTING_KEY, 'value' => []]);
        $menu = Menu::query()->create(['handle' => 'main-vi', 'name' => 'Trình đơn chính']);
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 0]);
        $item->translations()->create(['locale' => 'vi', 'label' => 'Dịch vụ']);
        $trashedItem = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 1]);
        $trashedItem->delete();

        [$one, $two] = $blocks;
        foreach ([
            ['content_block_id' => $one->id, 'entry_id' => $page->id, 'locale' => 'vi'],
            ['content_block_id' => $one->id, 'entry_id' => $page->id, 'locale' => 'en'],
            ['content_block_id' => $one->id, 'entry_id' => $post->id, 'locale' => 'vi'],
            ['content_block_id' => $one->id, 'taxonomy_id' => $category->id, 'slot' => 'post_aside', 'locale' => 'vi'],
            ['content_block_id' => $one->id, 'site_setting_id' => $setting->id, 'slot' => 'after_post', 'locale' => 'vi'],
            ['content_block_id' => $one->id, 'menu_item_id' => $item->id],
            ['content_block_id' => $two->id, 'entry_id' => $page->id, 'locale' => 'vi'],
            ['content_block_id' => $two->id, 'menu_item_id' => $trashedItem->id],
        ] as $row) {
            BlockUsage::query()->create($row);
        }

        return ['blocks' => $blocks, 'page' => $page->refresh(), 'category' => $category->refresh(), 'item' => $item->refresh()];
    });
}

it('counts places, not languages, and leaves out owners in the Trash, in one query', function (): void {
    $used = usedBlocks();
    $ids = array_map(fn (ContentBlock $block): int => $block->id, $used['blocks']);

    app(Tenancy::class)->enter(1, function () use ($ids): void {
        $counts = null;
        $queries = countQueries(function () use ($ids, &$counts): void {
            $counts = app(ContentBlockUsages::class)->countsFor($ids);
        });

        expect($queries)->toBe(1)
            ->and($counts)->toBe([$ids[0] => 4, $ids[1] => 1]);
    });

    // a restored post counts again
    app(Tenancy::class)->enter(1, fn () => Entry::onlyTrashed()->sole()->restore());
    expect(app(Tenancy::class)->enter(1, fn () => app(ContentBlockUsages::class)->countsFor([$ids[0]])))->toBe([$ids[0] => 5])
        ->and(app(Tenancy::class)->enter(2, fn () => app(ContentBlockUsages::class)->countsFor($ids)))->toBe([]);
});

it('lists the places of a block with their names, in a fixed number of queries', function (): void {
    $used = usedBlocks();

    $places = app(Tenancy::class)->enter(1, fn () => app(ContentBlockUsages::class)->placesOf($used['blocks'][0]));

    expect($places)->toBe([
        ['kind' => 'page', 'ulid' => $used['page']->ulid, 'name' => 'Giới thiệu', 'menu' => null, 'slot' => null, 'locales' => ['en', 'vi']],
        ['kind' => 'category', 'ulid' => $used['category']->ulid, 'name' => 'Tin tức', 'menu' => null, 'slot' => 'post_aside', 'locales' => ['vi']],
        ['kind' => 'site', 'ulid' => null, 'name' => null, 'menu' => null, 'slot' => 'after_post', 'locales' => ['vi']],
        ['kind' => 'menu_item', 'ulid' => $used['item']->ulid, 'name' => 'Dịch vụ', 'menu' => 'Trình đơn chính', 'slot' => null, 'locales' => []],
    ]);

    // more places of the same kinds cost no more queries
    $queries = fn (): int => app(Tenancy::class)->enter(1, fn () => countQueries(fn () => app(ContentBlockUsages::class)->placesOf($used['blocks'][0])));
    $before = $queries();
    app(Tenancy::class)->enter(1, function () use ($used): void {
        for ($i = 0; $i < 5; $i++) {
            $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
            BlockUsage::query()->create(['content_block_id' => $used['blocks'][0]->id, 'entry_id' => $entry->id, 'locale' => 'vi']);
        }
    });

    expect($queries())->toBe($before)
        ->and(app(Tenancy::class)->enter(1, fn () => app(ContentBlockUsages::class)->placesOf($used['blocks'][0])))->toHaveCount(9)
        ->and(app(Tenancy::class)->enter(1, fn () => app(ContentBlockUsages::class)->placesOf($used['blocks'][2])))->toBe([]);
});

it('starts from the first page when the cursor was not one it gave out', function (array $cursor): void {
    listedBlocks(4);

    $keys = app(Tenancy::class)->enter(1, fn (): array => array_map(
        fn (ContentBlock $block): string => $block->key,
        app(ContentBlockList::class)->page(after: $cursor, limit: 2)['rows'],
    ));

    expect($keys)->toBe(['b4', 'b3']);
})->with([
    'empty time' => [['at' => '', 'ulid' => '01JLISTED00000000000000003']],
    'not a time' => [['at' => 'hôm qua', 'ulid' => '01JLISTED00000000000000003']],
    'short code' => [['at' => '2026-11-01 08:03:00+00', 'ulid' => 'abc']],
]);
