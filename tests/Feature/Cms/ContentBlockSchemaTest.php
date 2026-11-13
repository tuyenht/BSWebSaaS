<?php

use App\Modules\Cms\Models\BlockUsage;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\LayoutSlots;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The block library's tables on real Postgres: RLS isolation (fail closed with no website, a
 * website sees and changes only its own rows), the composite (site_id, content_block_id) keys that
 * stop a language or a usage row hanging under another website's block, the CHECKs on code,
 * region, status, the single owner, slot and language of a usage, the uniques, the cascades, and a
 * clean down() and up(). Constraint violations let the exception leave enter(), so the aborted
 * transaction rolls back cleanly.
 */

beforeEach(fn () => seedTenancy());

/**
 * A block of $site, made inside its context.
 *
 * @param  array<string, mixed>  $attributes
 */
function schemaBlock(int $site, array $attributes = []): ContentBlock
{
    return app(Tenancy::class)->enter($site, fn (): ContentBlock => ContentBlock::query()->create(array_merge(
        ['key' => 'footer', 'name' => 'Chân trang'],
        $attributes,
    )));
}

it('creates a draft block meant for the page by default, with a ulid and the site stamped', function (): void {
    $block = schemaBlock(1);
    app(Tenancy::class)->enter(1, fn (): ContentBlock => $block->refresh());

    expect($block->site_id)->toBe(1)
        ->and($block->ulid)->toHaveLength(26)
        ->and($block->status)->toBe('draft')
        ->and($block->region)->toBe('inline');
});

it('enables and forces row level security with a policy on the three tables', function (): void {
    $rows = DB::select(<<<'SQL'
        select c.relname, c.relrowsecurity, c.relforcerowsecurity,
               (select count(*) from pg_policy p where p.polrelid = c.oid) as policies
        from pg_class c
        where c.relname in ('content_blocks', 'content_block_translations', 'block_usages')
        order by c.relname
    SQL);

    expect(array_map(fn ($row): array => [$row->relname, $row->relrowsecurity, $row->relforcerowsecurity, (int) $row->policies], $rows))->toBe([
        ['block_usages', true, true, 1],
        ['content_block_translations', true, true, 1],
        ['content_blocks', true, true, 1],
    ]);
});

it('shows no row of any of the three tables without a website (fail closed)', function (): void {
    $block = schemaBlock(1);
    app(Tenancy::class)->enter(1, function () use ($block): void {
        $block->translations()->create(['locale' => 'vi', 'body' => ['blocks' => []]]);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        BlockUsage::query()->create(['content_block_id' => $block->id, 'entry_id' => $entry->id, 'locale' => 'vi']);
    });

    expect(DB::table('content_blocks')->count())->toBe(0)
        ->and(DB::table('content_block_translations')->count())->toBe(0)
        ->and(DB::table('block_usages')->count())->toBe(0)
        ->and(DB::connection('pgsql_admin')->table('block_usages')->count())->toBe(1);
});

it("keeps one website's blocks, languages and usages out of another's reach", function (): void {
    $block = schemaBlock(1);
    app(Tenancy::class)->enter(1, function () use ($block): void {
        $block->translations()->create(['locale' => 'vi', 'body' => ['blocks' => []]]);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        BlockUsage::query()->create(['content_block_id' => $block->id, 'entry_id' => $entry->id, 'locale' => 'vi']);
    });

    app(Tenancy::class)->enter(2, function (): void {
        expect(ContentBlock::query()->count())->toBe(0)
            ->and(ContentBlockTranslation::query()->count())->toBe(0)
            ->and(BlockUsage::query()->count())->toBe(0)
            // raw reads and writes too: RLS, not only the model's scope
            ->and(DB::table('content_blocks')->count())->toBe(0)
            ->and(DB::table('content_blocks')->update(['name' => 'Đổi trộm']))->toBe(0)
            ->and(DB::table('content_block_translations')->delete())->toBe(0);
    });

    expect(app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->value('name')))->toBe('Chân trang');
});

it("refuses a block written into another website's rows (WITH CHECK)", function (): void {
    expect(fn () => app(Tenancy::class)->enter(1, fn () => DB::table('content_blocks')->insert([
        'ulid' => '01JCROSSSITE00000000000000', 'site_id' => 2, 'key' => 'footer', 'name' => 'Lạc nhà',
    ])))->toThrow(QueryException::class);
});

it("refuses a language under another website's block (composite same-site key)", function (): void {
    $other = schemaBlock(2);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => ContentBlockTranslation::query()->create([
        'content_block_id' => $other->id, 'locale' => 'vi', 'body' => ['blocks' => []],
    ])))->toThrow(QueryException::class);
});

it("refuses a usage row of another website's block (composite same-site key)", function (): void {
    $other = schemaBlock(2);

    expect(fn () => app(Tenancy::class)->enter(1, function () use ($other): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        BlockUsage::query()->create(['content_block_id' => $other->id, 'entry_id' => $entry->id, 'locale' => 'vi']);
    }))->toThrow(QueryException::class);
});

it('refuses a bad block row', function (array $attributes): void {
    expect(fn () => schemaBlock(1, $attributes))->toThrow(QueryException::class);
})->with([
    'unknown region' => [['region' => 'popup']],
    'unknown status' => [['status' => 'live']],
    'capital letters in the code' => [['key' => 'Footer']],
    'a space in the code' => [['key' => 'chan trang']],
    'a double dash in the code' => [['key' => 'chan--trang']],
    'a trailing dash in the code' => [['key' => 'chan-']],
    'accented letters in the code' => [['key' => 'chân-trang']],
    'a code over 64 characters' => [['key' => str_repeat('a', 65)]],
    'a blank name' => [['name' => '   ']],
    'a name over 120 characters' => [['name' => str_repeat('a', 121)]],
]);

it('accepts every region and a code of exactly 64 characters', function (): void {
    foreach (ContentBlock::REGIONS as $i => $region) {
        schemaBlock(1, ['key' => "khoi-{$i}", 'region' => $region]);
    }
    schemaBlock(1, ['key' => str_repeat('a', 64), 'status' => 'published']);

    expect(app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->count()))->toBe(count(ContentBlock::REGIONS) + 1);
});

it('keeps the reference code unique per website, compared without case', function (): void {
    schemaBlock(1);
    schemaBlock(2); // the same code on another website is fine

    expect(fn () => schemaBlock(1, ['name' => 'Chân trang 2']))->toThrow(QueryException::class)
        ->and(app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->where('key', 'FOOTER')->exists()))->toBeTrue();
});

it('keeps one content row per block and language', function (): void {
    $block = schemaBlock(1);

    expect(fn () => app(Tenancy::class)->enter(1, function () use ($block): void {
        $block->translations()->create(['locale' => 'vi', 'body' => ['blocks' => []]]);
        $block->translations()->create(['locale' => 'vi', 'body' => ['blocks' => []]]);
    }))->toThrow(QueryException::class);
});

it('refuses a content row that is not a JSON object', function (): void {
    $block = schemaBlock(1);

    expect(fn () => app(Tenancy::class)->enter(1, fn () => DB::table('content_block_translations')->insert([
        'site_id' => 1, 'content_block_id' => $block->id, 'locale' => 'vi', 'body' => '[]',
    ])))->toThrow(QueryException::class);
});

/**
 * The owners a usage row may name, made in site 1: a page, a category, the default slot setting
 * and a menu item.
 *
 * @return array{entry: int, taxonomy: int, setting: int, item: int}
 */
function schemaUsageOwners(): array
{
    return app(Tenancy::class)->enter(1, function (): array {
        $menu = Menu::query()->create(['handle' => 'main-vi', 'name' => 'Trình đơn chính']);

        return [
            'entry' => Entry::query()->create(['type' => 'page', 'status' => 'draft'])->id,
            'taxonomy' => Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0])->id,
            'setting' => SiteSetting::query()->create(['group_name' => LayoutSlots::SETTING_GROUP, 'key' => LayoutSlots::SETTING_KEY, 'value' => []])->id,
            'item' => MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 0])->id,
        ];
    });
}

it('accepts one owner per usage row with the slot and language that owner takes', function (): void {
    $block = schemaBlock(1);
    $owners = schemaUsageOwners();

    app(Tenancy::class)->enter(1, function () use ($block, $owners): void {
        BlockUsage::query()->create(['content_block_id' => $block->id, 'entry_id' => $owners['entry'], 'locale' => 'vi']);
        BlockUsage::query()->create(['content_block_id' => $block->id, 'taxonomy_id' => $owners['taxonomy'], 'slot' => 'post_aside', 'locale' => 'vi']);
        BlockUsage::query()->create(['content_block_id' => $block->id, 'menu_item_id' => $owners['item']]);
        foreach (LayoutSlots::SLOTS as $slot) { // the table's slot list matches LayoutSlots
            BlockUsage::query()->create(['content_block_id' => $block->id, 'site_setting_id' => $owners['setting'], 'slot' => $slot, 'locale' => 'vi']);
        }

        expect(BlockUsage::query()->count())->toBe(3 + count(LayoutSlots::SLOTS));
    });
});

it('refuses a usage row with the wrong owner, slot or language', function (Closure $row): void {
    $block = schemaBlock(1);
    $owners = schemaUsageOwners();

    expect(fn () => app(Tenancy::class)->enter(1, fn () => BlockUsage::query()->create(
        ['content_block_id' => $block->id, ...$row($owners)],
    )))->toThrow(QueryException::class);
})->with([
    'no owner' => [fn (array $o): array => ['locale' => 'vi']],
    'two owners' => [fn (array $o): array => ['entry_id' => $o['entry'], 'menu_item_id' => $o['item'], 'locale' => 'vi']],
    'a page with a slot' => [fn (array $o): array => ['entry_id' => $o['entry'], 'slot' => 'post_aside', 'locale' => 'vi']],
    'a category without a slot' => [fn (array $o): array => ['taxonomy_id' => $o['taxonomy'], 'locale' => 'vi']],
    'an unknown slot' => [fn (array $o): array => ['site_setting_id' => $o['setting'], 'slot' => 'header', 'locale' => 'vi']],
    'a page without a language' => [fn (array $o): array => ['entry_id' => $o['entry']]],
    'a menu item with a language' => [fn (array $o): array => ['menu_item_id' => $o['item'], 'locale' => 'vi']],
]);

it('keeps one usage row per place, the empty columns compared as equal', function (): void {
    $block = schemaBlock(1);
    $owners = schemaUsageOwners();

    expect(fn () => app(Tenancy::class)->enter(1, function () use ($block, $owners): void {
        BlockUsage::query()->create(['content_block_id' => $block->id, 'menu_item_id' => $owners['item']]);
        BlockUsage::query()->create(['content_block_id' => $block->id, 'menu_item_id' => $owners['item']]);
    }))->toThrow(QueryException::class);
});

it('drops the languages and usage rows with the block, and a usage row with its owner', function (): void {
    $block = schemaBlock(1);
    $kept = schemaBlock(1, ['key' => 'header', 'name' => 'Đầu trang']);
    $owners = schemaUsageOwners();

    app(Tenancy::class)->enter(1, function () use ($block, $kept, $owners): void {
        $block->translations()->create(['locale' => 'vi', 'body' => ['blocks' => []]]);
        BlockUsage::query()->create(['content_block_id' => $block->id, 'entry_id' => $owners['entry'], 'locale' => 'vi']);
        BlockUsage::query()->create(['content_block_id' => $kept->id, 'entry_id' => $owners['entry'], 'locale' => 'vi']);
        BlockUsage::query()->create(['content_block_id' => $kept->id, 'menu_item_id' => $owners['item']]);

        $block->delete();
        expect(ContentBlockTranslation::query()->count())->toBe(0)
            ->and(BlockUsage::query()->pluck('content_block_id')->unique()->all())->toBe([$kept->id]);

        Entry::query()->whereKey($owners['entry'])->forceDelete();
        MenuItem::query()->whereKey($owners['item'])->forceDelete();
        expect(BlockUsage::query()->count())->toBe(0)
            ->and(ContentBlock::query()->whereKey($kept->id)->exists())->toBeTrue();
    });
});

it('drops the three tables on down() and builds them again on up()', function (): void {
    $migration = require database_path('migrations/2058_01_01_000000_create_content_block_tables.php');
    $admin = DB::connection('pgsql_admin');
    $tables = fn (): array => array_map(fn ($row): string => $row->relname, $admin->select(
        "select relname from pg_class where relname in ('content_blocks', 'content_block_translations', 'block_usages') and relkind = 'r' order by relname",
    ));

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $admin->transaction(function () use ($migration, $tables, &$afterDown): void {
            $migration->down();
            $afterDown = $tables();
            $migration->up();
        });
    } finally {
        DB::setDefaultConnection($original);
    }

    expect($afterDown)->toBe([])
        ->and($tables())->toBe(['block_usages', 'content_block_translations', 'content_blocks']);
    // the app role can use the rebuilt tables, still under RLS
    $block = schemaBlock(1);
    expect(app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->whereKey($block->id)->exists()))->toBeTrue()
        ->and(app(Tenancy::class)->enter(2, fn () => ContentBlock::query()->count()))->toBe(0);
});
