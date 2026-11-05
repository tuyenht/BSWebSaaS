<?php

use App\Modules\Cms\Actions\Menus\ApplyMenuDraft;
use App\Modules\Cms\Actions\Menus\RestoreMenuItem;
use App\Modules\Cms\Actions\Menus\SaveMenu;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuVersion;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * "Lưu" on the Trình đơn screen: the whole draft of a menu in one transaction — new rows, edits,
 * the new order and nesting, removals to the trash — against the version the screen loaded. Rows
 * are named by ulid; a target or row of another site is never found. The depth limit is the
 * strictest theme area showing the menu, and only new depth is refused. Display options are
 * checked strictly and stored in menu_items.meta.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    $this->editors = [1 => menuEditor(1), 2 => menuEditor(2)];
});

function mdIn(int $site, Closure $callback): mixed
{
    return app(Tenancy::class)->enter($site, $callback);
}

function mdEditor(): User
{
    return test()->editors[app(CurrentSite::class)->id()];
}

function mdMenu(string $name = 'Menu chính'): Menu
{
    return app(SaveMenu::class)->handle(null, $name);
}

/** A live row with a vi label; position after its last sibling. */
function mdRow(Menu $menu, string $label, ?MenuItem $parent = null, array $meta = []): MenuItem
{
    $position = MenuItem::query()->where('menu_id', $menu->id)->where('parent_id', $parent?->id)->count();
    $item = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $parent?->id, 'position' => $position,
        'link_type' => 'dynamic', 'dynamic_key' => 'home', 'meta' => $meta]);
    $item->translations()->create(['locale' => 'vi', 'label' => $label]);

    return $item->fresh() ?? $item;
}

/** The version token and the tree of the menu as the screen would load them. */
function mdState(Menu $menu): array
{
    [$items, $labels] = app(ApplyMenuDraft::class)->read($menu->id);
    $ulid = $items->pluck('ulid', 'id')->all();
    $children = [];
    foreach ($items->sortBy([['position', 'asc'], ['id', 'asc']]) as $item) {
        $children[$item->parent_id ?? 0][] = $item;
    }
    $tree = [];
    $walk = function (int $parent) use (&$walk, &$tree, $children, $ulid): void {
        foreach ($children[$parent] ?? [] as $item) {
            $tree[] = ['key' => $item->ulid, 'parent' => $item->parent_id === null ? null : $ulid[$item->parent_id]];
            $walk($item->id);
        }
    };
    $walk(0);

    return ['version' => MenuVersion::of($menu->fresh(), $items, $labels), 'tree' => $tree];
}

/** Labels of the live tree: ['A' => ['A1' => []], …]. */
function mdTree(Menu $menu, ?int $parent = null): array
{
    $tree = [];
    foreach (MenuItem::query()->with('translations')->where('menu_id', $menu->id)->where('parent_id', $parent)->orderBy('position')->orderBy('id')->get() as $item) {
        $tree[(string) $item->translationFor('vi')?->label] = mdTree($menu, $item->id);
    }

    return $tree;
}

/** Stored meta comes back in jsonb key order: compare it sorted. */
function mdSorted(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    ksort($value);

    return array_map(mdSorted(...), $value);
}

function mdSave(Menu $menu, array $draft): array
{
    return app(ApplyMenuDraft::class)->handle(mdEditor(), $menu, $draft);
}

function mdRefusal(Closure $change): ?string
{
    try {
        $change();
    } catch (MenuChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

function mdPage(string $title, string $status = 'published'): Entry
{
    $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'published_at' => now()]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => 'trang-'.$entry->id, 'status' => 'published']);

    return $entry;
}

function mdShow(Menu $menu, string $area, string $locale = 'vi'): void
{
    MenuAssignment::query()->updateOrCreate(['region_key' => $area, 'locale' => $locale], ['menu_id' => $menu->id, 'same_as_region' => null]);
}

it('saves a whole draft at once: new rows by their own key, edits, order and nesting', function (): void {
    mdIn(1, function (): void {
        $menu = mdMenu();
        $home = mdRow($menu, 'Trang chủ');
        $about = mdRow($menu, 'Giới thiệu');
        $page = mdPage('ồ Dịch vụ ộ');
        $state = mdState($menu);

        $result = mdSave($menu, [
            'version' => $state['version'],
            'tree' => [
                ['key' => $about->ulid, 'parent' => null],
                ['key' => 'new:a', 'parent' => $about->ulid],
                ['key' => $home->ulid, 'parent' => null],
            ],
            'creates' => [['key' => 'new:a', 'link' => ['type' => 'entry', 'ref' => $page->ulid], 'labels' => ['vi' => 'ồ Dịch vụ ộ']]],
            'updates' => [['key' => $home->ulid, 'labels' => ['vi' => 'ồ Về đầu ộ']]],
        ]);

        expect(mdTree($menu))->toBe(['Giới thiệu' => ['ồ Dịch vụ ộ' => []], 'ồ Về đầu ộ' => []])
            ->and($result['keys'])->toHaveKey('new:a')
            ->and(MenuItem::query()->where('ulid', $result['keys']['new:a'])->value('routable_id'))->toBe($page->id)
            ->and($result['version'])->not->toBe($state['version'])
            ->and($result['version'])->toBe(mdState($menu)['version'])
            ->and($result['removed'])->toBe(0);
    });

    expect(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'cms.menu.draft_saved')->count())->toBe(1);
});

it('refuses a draft made from an older state of the menu, and writes nothing', function (): void {
    mdIn(1, function (): void {
        $menu = mdMenu();
        $home = mdRow($menu, 'Trang chủ');
        $old = mdState($menu);
        mdRow($menu, 'Thêm ở tab khác');   // another tab saved since

        $reason = mdRefusal(fn () => mdSave($menu, [
            'version' => $old['version'],
            'tree' => [['key' => $home->ulid, 'parent' => null]],
            'updates' => [['key' => $home->ulid, 'labels' => ['vi' => 'ồ Mới ộ']]],
        ]));

        expect($reason)->toBe('draft_stale')
            ->and(mdTree($menu))->toBe(['Trang chủ' => [], 'Thêm ở tab khác' => []]);
    });
});

it('never reaches another site: its page and its rows are simply not found', function (): void {
    [$foreignPage, $foreignRow] = mdIn(2, function (): array {
        $menu = mdMenu('Menu B');

        return [mdPage('Trang B')->ulid, mdRow($menu, 'Mục B')->ulid];
    });

    mdIn(1, function () use ($foreignPage, $foreignRow): void {
        $menu = mdMenu();
        $home = mdRow($menu, 'Trang chủ');
        $state = mdState($menu);

        expect(mdRefusal(fn () => mdSave($menu, [
            'version' => $state['version'],
            'tree' => [...$state['tree'], ['key' => 'new:x', 'parent' => null]],
            'creates' => [['key' => 'new:x', 'link' => ['type' => 'entry', 'ref' => $foreignPage]]],
        ])))->toBe('target_invalid')
            ->and(mdRefusal(fn () => mdSave($menu, [
                'version' => $state['version'],
                'tree' => [...$state['tree'], ['key' => $foreignRow, 'parent' => null]],
            ])))->toBe('item_invalid')
            ->and(mdRefusal(fn () => mdSave($menu, [
                'version' => $state['version'],
                'tree' => $state['tree'],
                'updates' => [['key' => $foreignRow, 'labels' => ['vi' => 'x']]],
            ])))->toBe('item_invalid')
            ->and(mdTree($menu))->toBe(['Trang chủ' => []]);
        expect($home->fresh()?->translationFor('vi')?->label)->toBe('Trang chủ');
    });

    mdIn(2, fn () => expect(MenuItem::query()->where('ulid', $foreignRow)->exists())->toBeTrue());
});

it('refuses a tree that forgets a row, names one twice, or loops', function (): void {
    mdIn(1, function (): void {
        $menu = mdMenu();
        $a = mdRow($menu, 'A');
        $b = mdRow($menu, 'B');
        $version = mdState($menu)['version'];

        expect(mdRefusal(fn () => mdSave($menu, ['version' => $version, 'tree' => [['key' => $a->ulid, 'parent' => null]]])))->toBe('item_invalid')
            ->and(mdRefusal(fn () => mdSave($menu, ['version' => $version, 'tree' => [
                ['key' => $a->ulid, 'parent' => null], ['key' => $a->ulid, 'parent' => null], ['key' => $b->ulid, 'parent' => null],
            ]])))->toBe('item_invalid')
            ->and(mdRefusal(fn () => mdSave($menu, ['version' => $version, 'tree' => [
                ['key' => $a->ulid, 'parent' => $b->ulid], ['key' => $b->ulid, 'parent' => $a->ulid],
            ]])))->toBe('parent_invalid')
            ->and(mdRefusal(fn () => mdSave($menu, ['version' => $version, 'tree' => 'nope'])))->toBe('item_invalid')
            ->and(mdTree($menu))->toBe(['A' => [], 'B' => []]);
    });
});

it('removes a branch to the trash in one go, and "Khôi phục" brings it back whole', function (): void {
    mdIn(1, function (): void {
        $menu = mdMenu();
        $home = mdRow($menu, 'Trang chủ');
        $services = mdRow($menu, 'ồ Dịch vụ ộ');
        $child = mdRow($menu, 'Gia công', $services);
        $state = mdState($menu);

        $result = mdSave($menu, [
            'version' => $state['version'],
            'tree' => [['key' => $home->ulid, 'parent' => null]],
            'removes' => [$services->ulid, $child->ulid],
        ]);

        $trashed = MenuItem::onlyTrashed()->whereIn('id', [$services->id, $child->id])->get(['id', 'deleted_at']);
        expect($result['removed'])->toBe(2)
            ->and(mdTree($menu))->toBe(['Trang chủ' => []])
            ->and($trashed)->toHaveCount(2)
            ->and($trashed->pluck('deleted_at')->map->toIso8601String()->unique())->toHaveCount(1);

        $restored = app(RestoreMenuItem::class)->handle(mdEditor(), MenuItem::onlyTrashed()->findOrFail($services->id));

        expect($restored)->toBe(2)
            ->and(mdTree($menu))->toBe(['Trang chủ' => [], 'ồ Dịch vụ ộ' => ['Gia công' => []]]);
    });
});

it('limits depth by the strictest area showing the menu, and refuses only new depth', function (): void {
    mdIn(1, function (): void {
        $menu = mdMenu();
        $a = mdRow($menu, 'A');
        $b = mdRow($menu, 'B', $a);

        // Shown nowhere: the fallback of 3 levels.
        $state = mdState($menu);
        $third = mdSave($menu, ['version' => $state['version'], 'tree' => [...$state['tree'], ['key' => 'new:c', 'parent' => $b->ulid]],
            'creates' => [['key' => 'new:c', 'link' => ['type' => 'special', 'key' => 'group']]]]);
        $c = MenuItem::query()->where('ulid', $third['keys']['new:c'])->firstOrFail();
        $state = mdState($menu);
        expect(mdRefusal(fn () => mdSave($menu, ['version' => $state['version'], 'tree' => [...$state['tree'], ['key' => 'new:d', 'parent' => $c->ulid]],
            'creates' => [['key' => 'new:d', 'link' => ['type' => 'special', 'key' => 'group']]]])))->toBe('depth_exceeded');

        // The phone menu (4) alone allows a fourth level; with the footer (2) too, the footer decides.
        mdShow($menu, 'handheld');
        $state = mdState($menu);
        expect(mdRefusal(fn () => mdSave($menu, ['version' => $state['version'], 'tree' => [...$state['tree'], ['key' => 'new:d', 'parent' => $c->ulid]],
            'creates' => [['key' => 'new:d', 'link' => ['type' => 'special', 'key' => 'group']]]])))->toBeNull();

        mdShow($menu, 'footer');
        $state = mdState($menu);
        // What is already deeper stays: saving the same tree with an edit goes through…
        expect(mdRefusal(fn () => mdSave($menu, ['version' => $state['version'], 'tree' => $state['tree'],
            'updates' => [['key' => $c->ulid, 'labels' => ['vi' => 'ồ Sâu ộ']]]])))->toBeNull();
        // …but nothing may become deeper than 2 now: not a new row, not a row moved down.
        $state = mdState($menu);
        $newSibling = mdRow($menu, 'E');
        $state = mdState($menu);
        $moved = array_map(fn (array $node): array => $node['key'] === $newSibling->ulid ? ['key' => $node['key'], 'parent' => $b->ulid] : $node, $state['tree']);
        expect(mdRefusal(fn () => mdSave($menu, ['version' => $state['version'], 'tree' => $moved])))->toBe('depth_exceeded')
            ->and(mdRefusal(fn () => mdSave($menu, ['version' => $state['version'], 'tree' => [...$state['tree'], ['key' => 'new:f', 'parent' => $a->ulid]],
                'creates' => [['key' => 'new:f', 'link' => ['type' => 'special', 'key' => 'group']]]])))->toBeNull();
    });
});

it('checks every display option strictly and keeps only the keys it knows', function (): void {
    mdIn(1, function (): void {
        $menu = mdMenu();
        mdShow($menu, 'primary');
        $top = mdRow($menu, 'Sản phẩm', meta: ['legacy' => 'kept']);
        $sub = mdRow($menu, 'Khuôn', $top);
        $state = mdState($menu);
        $save = fn (array $topOptions, array $subOptions = []) => mdSave($menu, ['version' => mdState($menu)['version'], 'tree' => $state['tree'], 'updates' => [
            ['key' => $top->ulid, 'options' => $topOptions], ['key' => $sub->ulid, 'options' => $subOptions],
        ]]);

        $save([
            'hidden' => false, 'new_tab' => true, 'icon' => 'phone', 'cta' => true, 'device' => 'desktop',
            'description' => ['vi' => '  <b>ồ Gia công</b>   chính xác ộ  '],
            'mega' => ['type' => 'columns', 'columns' => 3],
            'badge' => ['kind' => 'new', 'until' => '2026-12-31'],
            'smuggled' => '<script>',
        ], ['mega' => ['type' => 'columns', 'columns' => 2], 'device' => 'phone']);

        expect(mdSorted($top->fresh()?->meta))->toBe(mdSorted([
            'legacy' => 'kept', 'target' => '_blank', 'cta' => true, 'icon' => 'phone',
            'description' => ['vi' => 'ồ Gia công chính xác ộ'],
            'mega' => ['type' => 'columns', 'columns' => 3],
            'badge' => 'new', 'badge_until' => '2026-12-31T16:59:59Z',   // end of that day in Hà Nội time
            'device' => 'desktop',
        ]))
            // a sub-item cannot open a large panel: dropped, the rest kept
            ->and($sub->fresh()?->meta)->toBe(['device' => 'phone']);

        $refused = fn (array $options): ?string => mdRefusal(fn () => $save($options));
        expect($refused(['icon' => 'not-an-icon']))->toBe('option_invalid')
            ->and($refused(['description' => ['vi' => str_repeat('ồ', 501)]]))->toBe('option_invalid')
            ->and($refused(['description' => ['fr' => 'Bonjour']]))->toBe('locale_invalid')
            ->and($refused(['mega' => ['type' => 'blocks', 'columns' => 3]]))->toBe('option_invalid')
            ->and($refused(['mega' => ['type' => 'columns', 'columns' => 5]]))->toBe('option_invalid')
            ->and($refused(['badge' => ['kind' => 'free-text']]))->toBe('option_invalid')
            ->and($refused(['badge' => ['kind' => 'hot', 'until' => '2026-02-31']]))->toBe('option_invalid')
            ->and($refused(['device' => 'tablet']))->toBe('option_invalid')
            ->and($refused(['hidden' => 'yes']))->toBe('option_invalid')
            // 500 letters with diacritics fit: counted as letters, not bytes
            ->and($refused(['description' => ['vi' => str_repeat('ộ', 500)]]))->toBeNull();
    });
});

it('drops a large panel from an item moved under another, and where no area can draw one', function (): void {
    mdIn(1, function (): void {
        $menu = mdMenu();
        $a = mdRow($menu, 'A', meta: ['mega' => ['type' => 'columns', 'columns' => 4]]);
        $b = mdRow($menu, 'B');
        $state = mdState($menu);

        mdSave($menu, ['version' => $state['version'], 'tree' => [['key' => $b->ulid, 'parent' => null], ['key' => $a->ulid, 'parent' => $b->ulid]]]);
        expect($a->fresh()?->meta)->toBe([]);

        // Shown only in the footer, which draws no large panel: the option is not kept.
        mdShow($menu, 'footer');
        $state = mdState($menu);
        mdSave($menu, ['version' => $state['version'], 'tree' => $state['tree'], 'updates' => [['key' => $b->ulid, 'options' => ['mega' => ['type' => 'columns', 'columns' => 2]]]]]);
        expect($b->fresh()?->meta)->toBe([]);
    });
});

it('refuses the draft of someone who may not manage menus', function (): void {
    $stranger = User::factory()->create();

    mdIn(1, function () use ($stranger): void {
        $menu = mdMenu();
        mdRow($menu, 'Trang chủ');
        $state = mdState($menu);

        expect(fn () => app(ApplyMenuDraft::class)->handle($stranger, $menu, ['version' => $state['version'], 'tree' => []]))
            ->toThrow(AuthorizationException::class)
            ->and(mdTree($menu))->toBe(['Trang chủ' => []]);
    });
});

it('names a tree past the item cap as a full menu, not a broken request', function (): void {
    mdIn(1, function (): void {
        $menu = mdMenu();
        $state = mdState($menu);
        $tree = array_map(fn (int $i): array => ['key' => 'new:'.$i, 'parent' => null], range(0, ApplyMenuDraft::ITEMS_MAX));

        expect(mdRefusal(fn () => mdSave($menu, ['version' => $state['version'], 'tree' => $tree, 'creates' => [], 'updates' => [], 'removes' => []])))->toBe('menu_full');
    });
});

it('moves rows only inside their own menu and site, even through the batched update', function (): void {
    [$otherMenu, $otherRow] = mdIn(2, function (): array {
        $menu = mdMenu('ồ Menu B ộ');

        return [$menu, mdRow($menu, 'ồ B1 ộ')];
    });

    mdIn(1, function (): void {
        $menu = mdMenu();
        $a = mdRow($menu, 'ồ A ộ');
        $b = mdRow($menu, 'ồ B ộ');
        $state = mdState($menu);

        mdSave($menu, ['version' => $state['version'], 'tree' => [['key' => $b->ulid, 'parent' => null], ['key' => $a->ulid, 'parent' => $b->ulid]], 'creates' => [], 'updates' => [], 'removes' => []]);

        expect(mdTree($menu))->toBe(['ồ B ộ' => ['ồ A ộ' => []]]);
    });

    mdIn(2, function () use ($otherRow): void {
        expect(MenuItem::query()->whereKey($otherRow->id)->first(['parent_id', 'position'])?->only(['parent_id', 'position']))->toBe(['parent_id' => null, 'position' => 0]);
    });
});
