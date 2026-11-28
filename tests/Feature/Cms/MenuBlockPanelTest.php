<?php

use App\Modules\Admin\Livewire\Menus\Index as MenusIndex;
use App\Modules\Admin\Support\Menus\MenuScreen;
use App\Modules\Admin\Support\Menus\MenuSelfCheck;
use App\Modules\Cms\Actions\Menus\ApplyMenuDraft;
use App\Modules\Cms\Actions\Menus\DuplicateMenu;
use App\Modules\Cms\Actions\Menus\DuplicateMenuItem;
use App\Modules\Cms\Actions\Menus\SaveMenu;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\BlockUsage;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuItemOptions;
use App\Modules\Cms\Navigation\MenuResolver;
use App\Modules\Cms\Navigation\MenuVersion;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * A large panel of the main menu built from one of the website's shared blocks (Khối dùng chung):
 * stored as mega = {type: block, block: ulid}, on top-level items of menus whose areas draw a large
 * panel. The save takes only a block of this website (another website's ulid, or one never issued,
 * is refused); the website draws the published block in the desktop panel, also for an item with no
 * children, keeps the children in the phone menu, and shows a plain link when the block is a draft
 * or gone. The screen lists the blocks of the large-panel region and the self-check names a panel
 * whose block is missing or a draft.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    $this->editors = [1 => menuEditor(1), 2 => menuEditor(2)];
    app()->setLocale('vi');
});

function mbpIn(int $site, Closure $callback): mixed
{
    return app(Tenancy::class)->enter($site, $callback);
}

/** A shared block of the current site with one Vietnamese call-to-action band. */
function mbpBlock(string $key, string $status = 'published', string $heading = '', string $region = 'menu'): ContentBlock
{
    $block = ContentBlock::query()->create(['key' => $key, 'name' => 'Khối '.$key, 'region' => $region, 'status' => $status]);
    ContentBlockTranslation::query()->create(['content_block_id' => $block->id, 'locale' => 'vi', 'body' => ['blocks' => [
        ['type' => 'cta', 'props' => ['heading' => $heading !== '' ? $heading : 'Bảng lớn '.$key, 'anchor_id' => 'lien-he', 'motion' => 'rise']],
    ]]]);

    return $block;
}

/** A menu of the current site shown in $area (the main menu draws large panels). */
function mbpMenu(string $area = 'primary'): Menu
{
    $menu = app(SaveMenu::class)->handle(null, 'Menu '.$area);
    MenuAssignment::query()->updateOrCreate(['region_key' => $area, 'locale' => 'vi'], ['menu_id' => $menu->id, 'same_as_region' => null]);

    return $menu;
}

/** @param array<string, mixed> $meta */
function mbpRow(Menu $menu, string $label, ?MenuItem $parent = null, array $meta = []): MenuItem
{
    $position = MenuItem::query()->where('menu_id', $menu->id)->where('parent_id', $parent?->id)->count();
    $item = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $parent?->id, 'position' => $position,
        'link_type' => 'url', 'url' => 'https://example.com/'.$position.'-'.($parent?->id ?? 0), 'meta' => $meta]);
    $item->translations()->create(['locale' => 'vi', 'label' => $label]);

    return $item->fresh() ?? $item;
}

/** @return array{version: string, tree: list<array{key: string, parent: string|null}>} */
function mbpState(Menu $menu): array
{
    [$items, $labels] = app(ApplyMenuDraft::class)->read($menu->id);
    $ulid = $items->pluck('ulid', 'id')->all();
    $tree = [];
    foreach ($items->sortBy([['parent_id', 'asc'], ['position', 'asc'], ['id', 'asc']]) as $item) {
        $tree[] = ['key' => (string) $item->ulid, 'parent' => $item->parent_id === null ? null : $ulid[$item->parent_id]];
    }
    usort($tree, fn (array $a, array $b): int => ($a['parent'] === null ? 0 : 1) <=> ($b['parent'] === null ? 0 : 1));

    return ['version' => MenuVersion::of($menu->fresh() ?? $menu, $items, $labels), 'tree' => $tree];
}

/** Saves $options on $item as the screen does, answering the refusal reason or null. */
function mbpSave(Menu $menu, MenuItem $item, array $options): ?string
{
    $state = mbpState($menu);
    try {
        app(ApplyMenuDraft::class)->handle(test()->editors[app(CurrentSite::class)->id()], $menu, [
            'version' => $state['version'], 'tree' => $state['tree'], 'updates' => [['key' => $item->ulid, 'options' => $options]],
        ]);
    } catch (MenuChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

/** The menu items whose large panels place a block, read in the current site (and its open transaction): "site:block key:item id". */
function mbpPlaced(): array
{
    return DB::table('block_usages')
        ->join('content_blocks', 'content_blocks.id', '=', 'block_usages.content_block_id')
        ->whereNotNull('block_usages.menu_item_id')->orderBy('block_usages.menu_item_id')
        ->get(['block_usages.site_id', 'content_blocks.key', 'block_usages.menu_item_id'])
        ->map(fn (object $row): string => "{$row->site_id}:{$row->key}:{$row->menu_item_id}")->all();
}

function mbpRichHeader(int $site = 1): void
{
    seedHomepage($site, 'Trang chủ');
    mbpIn($site, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => ['sticky' => false]]));
}

function mbpDesk(string $html): string
{
    preg_match('~<nav[^>]*class="sf-nav sf-nav--desk".*?</nav>~s', $html, $desk);

    return $desk[0] ?? '';
}

function mbpDrawer(string $html): string
{
    preg_match('~<nav class="sf-nav sf-nav--mob[^"]*".*?</nav>~s', $html, $drawer);

    return $drawer[0] ?? '';
}

// ---- the stored option ----------------------------------------------------------------------

it('reads and writes the block-built panel as its own kind of large panel', function (): void {
    $ulid = '01JABCDEFGHJKMNPQRSTVWXYZ0';
    $stored = MenuItemOptions::normalize([], ['mega' => ['type' => 'block', 'block' => strtolower($ulid)]], ['vi'], true, true, 'Asia/Ho_Chi_Minh');

    expect($stored['mega'])->toBe(['type' => 'block', 'block' => $ulid])
        // chosen later: the kind is kept with no block yet
        ->and(MenuItemOptions::normalize([], ['mega' => ['type' => 'block', 'block' => null]], ['vi'], true, true, 'UTC')['mega'])->toBe(['type' => 'block'])
        ->and(MenuItemOptions::forScreen(['mega' => ['type' => 'block']])['mega'])->toBe(['type' => 'block', 'block' => null])
        ->and(MenuItemOptions::forScreen($stored)['mega'])->toBe(['type' => 'block', 'block' => $ulid])
        ->and(MenuItemOptions::forRender($stored, 'vi', now()->toImmutable()))->toMatchArray(['mega' => null, 'mega_block' => $ulid])
        ->and(MenuItemOptions::forRender(['mega' => ['type' => 'columns', 'columns' => 2]], 'vi', now()->toImmutable()))->toMatchArray(['mega' => 2, 'mega_block' => null])
        // a sub-item, or a menu no area draws a large panel for, keeps none
        ->and(MenuItemOptions::normalize([], ['mega' => ['type' => 'block', 'block' => $ulid]], ['vi'], false, true, 'UTC'))->not->toHaveKey('mega')
        ->and(MenuItemOptions::normalize([], ['mega' => ['type' => 'block', 'block' => $ulid]], ['vi'], true, false, 'UTC'))->not->toHaveKey('mega');

    foreach ([['type' => 'block', 'block' => 'not-a-ulid'], ['type' => 'block', 'block' => 42], ['type' => 'blocks', 'block' => $ulid], 'block'] as $bad) {
        expect(fn () => MenuItemOptions::normalize([], ['mega' => $bad], ['vi'], true, true, 'UTC'))->toThrow(MenuChangeRejectedException::class);
    }
});

// ---- the save -------------------------------------------------------------------------------

it('saves a block of this website on a top-level item, places it, and changes the version', function (): void {
    mbpIn(1, function (): void {
        $first = mbpBlock('bang-lon');
        $second = mbpBlock('bang-lon-2', ContentBlock::DRAFT);
        $menu = mbpMenu();
        $item = mbpRow($menu, 'Sản phẩm');
        $before = mbpState($menu)['version'];

        expect(mbpSave($menu, $item, ['mega' => ['type' => 'block', 'block' => strtolower($first->ulid)]]))->toBeNull()
            ->and($item->fresh()?->meta['mega'])->toBe(['type' => 'block', 'block' => $first->ulid])
            ->and(mbpPlaced())->toBe(["1:bang-lon:{$item->id}"]);

        $withFirst = mbpState($menu)['version'];
        // a draft block may be chosen: the website shows the panel once it is published
        expect(mbpSave($menu, $item, ['mega' => ['type' => 'block', 'block' => $second->ulid]]))->toBeNull()
            ->and(mbpPlaced())->toBe(["1:bang-lon-2:{$item->id}"])
            ->and($withFirst)->not->toBe($before)
            ->and(mbpState($menu)['version'])->not->toBe($withFirst);

        // back to columns: the row goes
        expect(mbpSave($menu, $item, ['mega' => ['type' => 'columns', 'columns' => 3]]))->toBeNull()
            ->and(mbpPlaced())->toBe([]);
    });
});

it('refuses another website’s block and one never issued, and leaves the menu as it was', function (): void {
    $foreign = mbpIn(2, fn (): ContentBlock => mbpBlock('cua-b'));

    mbpIn(1, function () use ($foreign): void {
        $menu = mbpMenu();
        $item = mbpRow($menu, 'Sản phẩm');

        expect(mbpSave($menu, $item, ['mega' => ['type' => 'block', 'block' => $foreign->ulid]]))->toBe('block_invalid')
            ->and(mbpSave($menu, $item, ['mega' => ['type' => 'block', 'block' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']]))->toBe('block_invalid')
            ->and(mbpSave($menu, $item, ['mega' => ['type' => 'block', 'block' => 'nope']]))->toBe('option_invalid')
            ->and($item->fresh()?->meta)->toBe([])
            ->and(mbpPlaced())->toBe([]);

        // a new item of the draft is checked too
        $state = mbpState($menu);
        $refused = null;
        try {
            app(ApplyMenuDraft::class)->handle(test()->editors[1], $menu, [
                'version' => $state['version'], 'tree' => [...$state['tree'], ['key' => 'new:x', 'parent' => null]],
                'creates' => [['key' => 'new:x', 'link' => ['type' => 'url', 'url' => '/x'], 'labels' => ['vi' => 'X'], 'options' => ['mega' => ['type' => 'block', 'block' => $foreign->ulid]]]],
            ]);
        } catch (MenuChangeRejectedException $e) {
            $refused = $e->reason;
        }
        expect($refused)->toBe('block_invalid')
            ->and(MenuItem::query()->where('menu_id', $menu->id)->count())->toBe(1);
    });
});

it('keeps the block an item already showed when it is gone since, so the rest still saves', function (): void {
    mbpIn(1, function (): void {
        $block = mbpBlock('se-xoa');
        $menu = mbpMenu();
        $item = mbpRow($menu, 'Sản phẩm', meta: ['mega' => ['type' => 'block', 'block' => $block->ulid]]);
        DB::table('block_usages')->delete();
        $block->delete();

        expect(mbpSave($menu, $item, ['mega' => ['type' => 'block', 'block' => $block->ulid], 'cta' => true]))->toBeNull()
            ->and($item->fresh()?->meta)->toMatchArray(['cta' => true, 'mega' => ['type' => 'block', 'block' => $block->ulid]]);
    });
});

it('writes where the panel places its block inside the save’s own transaction', function (): void {
    mbpIn(1, function (): void {
        $block = mbpBlock('bang-lon');
        $menu = mbpMenu();
        $item = mbpRow($menu, 'Sản phẩm');

        try {
            DB::transaction(function () use ($menu, $item, $block): void {
                expect(mbpSave($menu, $item, ['mega' => ['type' => 'block', 'block' => $block->ulid]]))->toBeNull()
                    ->and(BlockUsage::query()->whereNotNull('menu_item_id')->count())->toBe(1);
                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        expect(mbpPlaced())->toBe([])
            ->and($item->fresh()?->meta)->toBe([]);
    });
});

it('places the copied panels’ blocks when a menu or an item is duplicated', function (): void {
    mbpIn(1, function (): void {
        $block = mbpBlock('bang-lon');
        $menu = mbpMenu();
        $item = mbpRow($menu, 'Sản phẩm', meta: ['mega' => ['type' => 'block', 'block' => $block->ulid]]);
        mbpRow($menu, 'Khuôn', $item);
        mbpSave($menu, $item, ['mega' => ['type' => 'block', 'block' => $block->ulid]]);

        $copy = app(DuplicateMenuItem::class)->handle(test()->editors[1], $menu, $item);
        $menuCopy = app(DuplicateMenu::class)->handle(test()->editors[1], $menu);
        $inCopy = MenuItem::query()->where('menu_id', $menuCopy->id)->whereNull('parent_id')->orderBy('id')->pluck('id')->all();

        expect(mbpPlaced())->toBe(["1:bang-lon:{$item->id}", "1:bang-lon:{$copy->id}", ...array_map(fn (int $id): string => "1:bang-lon:{$id}", $inCopy)]);
    });
});

// ---- the website ----------------------------------------------------------------------------

it('draws the published block in the desktop panel, also for an item with no children, and keeps the children on the phone', function (): void {
    mbpRichHeader();
    mbpIn(1, function (): void {
        $block = mbpBlock('bang-lon', heading: 'ồ Ưu đãi tháng này ộ');
        $menu = mbpMenu();
        $products = mbpRow($menu, 'Sản phẩm', meta: ['mega' => ['type' => 'block', 'block' => $block->ulid]]);
        mbpRow($menu, 'Khuôn mẫu', $products);
        mbpRow($menu, 'Khuyến mãi', meta: ['mega' => ['type' => 'block', 'block' => $block->ulid]]);
    });

    $html = $this->get('http://a.test/')->assertOk()->getContent();
    $desk = mbpDesk($html);
    $drawer = mbpDrawer($html);

    expect(substr_count($desk, 'sf-nav__panel sf-nav__panel--block'))->toBe(2)
        ->and(substr_count($desk, 'ồ Ưu đãi tháng này ộ'))->toBe(2)
        // the panel shows the block, not the children; no link anchor, no scroll-in motion
        ->and($desk)->not->toContain('Khuôn mẫu')->not->toContain('id="lien-he"')->not->toContain('sf-s--m-rise')
        // the item with no children opens its panel too: its link, then the panel's button
        ->and($desk)->toMatch('~<a class="sf-nav__link" href="https://example.com/1-0"[^>]*>(?:(?!</a>).)*Khuyến mãi(?:(?!</a>).)*</a><button type="button" class="sf-nav__chev"~su')
        ->and($drawer)->toContain('Khuôn mẫu')->not->toContain('ồ Ưu đãi tháng này ộ');
});

it('shows a plain link when the block is a draft, gone or another website’s', function (string $case): void {
    mbpRichHeader();
    $foreign = mbpIn(2, fn (): ContentBlock => mbpBlock('cua-b', heading: 'Khối của website khác'));
    mbpIn(1, function () use ($case, $foreign): void {
        $ulid = match ($case) {
            'draft' => mbpBlock('nhap', ContentBlock::DRAFT, 'Khối còn nháp')->ulid,
            'gone' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            'foreign' => $foreign->ulid,
            'none chosen' => null,
        };
        $menu = mbpMenu();
        mbpRow($menu, 'Khuyến mãi', meta: ['mega' => array_filter(['type' => 'block', 'block' => $ulid])]);
        $parent = mbpRow($menu, 'Sản phẩm', meta: ['mega' => array_filter(['type' => 'block', 'block' => $ulid])]);
        mbpRow($menu, 'Khuôn mẫu', $parent);
    });

    $desk = mbpDesk($this->get('http://a.test/')->assertOk()->getContent());

    expect($desk)->not->toContain('sf-nav__panel--block')->not->toContain('Khối còn nháp')->not->toContain('Khối của website khác')
        // the childless item is a link; the one with children keeps its usual list
        ->and($desk)->toMatch('~<a[^>]*href="https://example.com/0-0"[^>]*>.*?Khuyến mãi~su')
        ->and($desk)->toContain('Khuôn mẫu');
})->with(['draft', 'gone', 'foreign', 'none chosen']);

it('reads the panels’ blocks once a request, and sees a change and the right website on the next one', function (): void {
    $resolver = app(MenuResolver::class);   // kept, as an Octane worker keeps it
    $panel = fn (int $site): ?string => mbpIn($site, function () use ($resolver): ?string {
        $node = $resolver->resolve('primary', 'vi', 'vi', '/')[0] ?? [];
        $html = $node['panel'] ?? null;

        return $html instanceof HtmlString ? $html->toHtml() : null;
    });
    foreach ([1 => 'Khối site A', 2 => 'Khối site B'] as $site => $heading) {
        mbpIn($site, function () use ($heading): void {
            $block = mbpBlock('bang-lon', heading: $heading);
            mbpRow(mbpMenu(), 'Sản phẩm', meta: ['mega' => ['type' => 'block', 'block' => $block->ulid]]);
        });
    }

    expect($panel(1))->toContain('Khối site A')
        ->and($panel(2))->toContain('Khối site B')->not->toContain('Khối site A');

    mbpIn(1, fn () => ContentBlockTranslation::query()->update(['body' => json_encode(['blocks' => [['type' => 'cta', 'props' => ['heading' => 'Khối site A đã sửa']]]])]));
    // the same request keeps the answer it read: the edit shows only on the next one
    expect($panel(1))->toContain('Khối site A')->not->toContain('đã sửa');

    app()->forgetScopedInstances();   // the next request (Octane resets scoped bindings)
    $cold = countQueries(fn () => expect($panel(1))->toContain('Khối site A đã sửa'));
    $warm = countQueries(fn () => $panel(1));
    expect($warm)->toBeLessThan($cold);   // a second menu of the request reads no block again
});

it('loads the scripts a panel’s block needs on every page that shows it', function (bool $published, bool $headerBar): void {
    // each entry the head asks Vite for, as a plain script tag
    $this->swap(Vite::class, new class extends Vite
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
        }

        // the rest as withoutVite() fakes it: no build manifest is needed (CI runs Pest without one)
        public function asset($asset, $buildDirectory = null): string
        {
            return '';
        }

        public function content($asset, $buildDirectory = null): string
        {
            return '';
        }

        public function preloadedAssets(): array
        {
            return [];
        }
    });
    $headerBar ? mbpRichHeader() : seedHomepage(1, 'Trang chủ');   // the plain header lists the menu as links: no panel
    mbpIn(1, function () use ($published): void {
        $block = ContentBlock::query()->create(['key' => 'so-lieu', 'name' => 'Số liệu', 'region' => 'menu', 'status' => $published ? 'published' : ContentBlock::DRAFT]);
        ContentBlockTranslation::query()->create(['content_block_id' => $block->id, 'locale' => 'vi', 'body' => ['blocks' => [
            ['type' => 'stats', 'props' => ['items' => [['value' => 15, 'suffix' => '+', 'label' => 'Năm']]]],
        ]]]);
        mbpRow(mbpMenu(), 'Năng lực', meta: ['mega' => ['type' => 'block', 'block' => $block->ulid]]);
    });
    $shown = $published && $headerBar;

    foreach (['/' => 200, '/khong-co-trang-nay' => 404] as $path => $status) {
        $html = $this->get('http://a.test'.$path)->assertStatus($status)->getContent();
        expect(str_contains($html, '<script type="module" src="/resources/js/sf-stats.js"></script>'))->toBe($shown, $path)
            ->and(str_contains($html, 'data-count="15"'))->toBe($shown, $path);
    }
})->with(['published, header bar' => [true, true], 'draft, header bar' => [false, true], 'published, plain header' => [true, false]]);

it('reads no panel block for an area that draws no large panel', function (): void {
    mbpIn(1, function (): void {
        $block = mbpBlock('bang-lon');
        mbpRow(mbpMenu('footer'), 'Sản phẩm', meta: ['mega' => ['type' => 'block', 'block' => $block->ulid]]);
    });
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    $tree = mbpIn(1, fn (): array => app(MenuResolver::class)->resolveMany(['footer'], 'vi', 'vi', '/')['footer'] ?? []);

    expect($tree)->toHaveCount(1)
        ->and($tree[0]['panel'])->toBeNull()
        ->and($tree[0])->not->toHaveKey('panel_scripts')
        ->and(array_filter($sql, fn (string $q): bool => str_contains($q, 'content_block')))->toBe([]);
});

// ---- the screen -----------------------------------------------------------------------------

it('keeps the chosen block in the list when the website has more blocks than the list shows', function (): void {
    $menu = mbpIn(1, function (): Menu {
        $rows = [];
        for ($i = 0; $i < MenuScreen::PANEL_BLOCKS_MAX + 5; $i++) {
            $rows[] = ['site_id' => 1, 'ulid' => (string) Str::ulid(), 'key' => sprintf('a-%03d', $i), 'name' => sprintf('A %03d', $i), 'region' => 'menu', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()];
        }
        ContentBlock::query()->insert($rows);
        $late = mbpBlock('z-cuoi');
        $menu = mbpMenu();
        mbpRow($menu, 'Sản phẩm', meta: ['mega' => ['type' => 'block', 'block' => $late->ulid]]);

        return $menu;
    });

    $blocks = mbpIn(1, fn (): array => app(MenuScreen::class)->load($menu)['menu']['blocks']);

    expect($blocks)->toHaveCount(MenuScreen::PANEL_BLOCKS_MAX + 2)   // the cap leaves room for one chosen block, then reads the cut one
        ->and(end($blocks)['name'])->toBe('Khối z-cuoi');
});

it('lists the large-panel blocks on the screen, with drafts marked and the chosen one kept', function (): void {
    mbpIn(2, fn () => mbpBlock('cua-b'));
    $menu = mbpIn(1, function (): Menu {
        mbpBlock('b-bang-lon');
        mbpBlock('a-nhap', ContentBlock::DRAFT);
        $inline = mbpBlock('c-trong-trang', region: 'inline');
        mbpBlock('d-chan-trang', region: 'footer');
        $menu = mbpMenu();
        mbpRow($menu, 'Sản phẩm', meta: ['mega' => ['type' => 'block', 'block' => $inline->ulid]]);

        return $menu;
    });

    $blocks = mbpIn(1, fn (): array => app(MenuScreen::class)->load($menu)['menu']['blocks']);

    expect(array_map(fn (array $b): array => [$b['name'], $b['draft']], $blocks))->toBe([
        ['Khối a-nhap', true], ['Khối b-bang-lon', false], ['Khối c-trong-trang', false],
    ]);
});

it('names a panel whose block is missing or a draft in the self-check', function (): void {
    $menu = mbpIn(1, function (): Menu {
        $draft = mbpBlock('nhap', ContentBlock::DRAFT);
        $live = mbpBlock('xuat-ban');
        $menu = mbpMenu();
        mbpRow($menu, 'Chưa chọn', meta: ['mega' => ['type' => 'block']]);
        mbpRow($menu, 'Mất khối', meta: ['mega' => ['type' => 'block', 'block' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']]);
        mbpRow($menu, 'Còn nháp', meta: ['mega' => ['type' => 'block', 'block' => $draft->ulid]]);
        mbpRow($menu, 'Đang hiện', meta: ['mega' => ['type' => 'block', 'block' => $live->ulid]]);
        mbpRow($menu, 'Ẩn', meta: ['hidden' => true, 'mega' => ['type' => 'block']]);

        return $menu;
    });

    $issues = collect(mbpIn(1, fn (): array => app(MenuSelfCheck::class)->check($menu)))->whereIn('code', ['block_missing', 'block_draft'])->values();

    expect($issues->map(fn (array $q): array => [$q['code'], $q['label'], $q['tone'], $q['action']])->all())->toBe([
        ['block_missing', 'Chưa chọn', 'danger', 'blocks'],
        ['block_missing', 'Mất khối', 'danger', 'blocks'],
        ['block_draft', 'Còn nháp', 'warning', 'blocks'],
    ])
        ->and($issues[0]['message'])->toBe('“Chưa chọn” bật bảng lớn dùng khối tự dựng nhưng chưa chọn được khối — khách chỉ thấy liên kết thường, không có bảng lớn.')
        ->and($issues[2]['message'])->toBe('Khối “Khối nhap” dùng cho “Còn nháp” còn nháp — khách chỉ thấy liên kết thường, chưa có bảng lớn cho tới khi khối được xuất bản.');

    // shown only where no large panel is drawn: nothing to say
    mbpIn(1, fn () => MenuAssignment::query()->update(['region_key' => 'footer']));
    expect(collect(mbpIn(1, fn (): array => app(MenuSelfCheck::class)->check($menu)))->whereIn('code', ['block_missing', 'block_draft'])->all())->toBe([]);
});

it('links to the shared blocks only for someone who may build them on a plan that has them', function (bool $may, bool $module, bool $linked): void {
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['menus.manage', 'blocks.manage'] as $permission) {
        Permission::findOrCreate($permission, PermissionRegistry::GUARD);
    }
    mbpIn(1, fn () => grantRole($user, 'menu-block-editor', $may ? ['menus.manage', 'blocks.manage'] : ['menus.manage']));
    app(TeamContext::class)->forget();
    if ($module) {
        DB::connection('pgsql_admin')->table('site_modules')->insert(['site_id' => 1, 'module_key' => 'blocks', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    mbpIn(1, function () use ($user, $linked): void {
        $test = Livewire::actingAs($user->fresh())->test(MenusIndex::class);
        $linked
            ? $test->assertViewHas('blocks', fn (?string $url): bool => $url !== null && str_contains($url, '/admin/blocks?') && str_contains($url, 'region=menu') && str_contains($url, 'return=menus'))
            : $test->assertViewHas('blocks', null);
    });
})->with([
    'block builder on a plan with blocks' => [true, true, true],
    'menu editor only' => [false, true, false],
    'plan without blocks' => [true, false, false],
]);
