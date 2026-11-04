<?php

use App\Modules\Cms\Actions\Menus\AssignMenu;
use App\Modules\Cms\Actions\Menus\DeleteMenuItem;
use App\Modules\Cms\Actions\Menus\FollowMenuArea;
use App\Modules\Cms\Actions\Menus\MoveMenuItem;
use App\Modules\Cms\Actions\Menus\RestoreMenuItem;
use App\Modules\Cms\Actions\Menus\SaveMenu;
use App\Modules\Cms\Actions\Menus\SaveMenuItem;
use App\Modules\Cms\Data\MenuItemData;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Navigation\MenuResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The data behind the Categories and Menus screens: the new columns and their checks, the row keys
 * (`cms:fill-ulids`), a region that shows another region's menu ("Dùng trình đơn chung"), and the
 * menu trash — removing moves a whole branch to the trash, "Khôi phục" brings it back, and the
 * nightly purge deletes what has waited 30 days. Every step stays inside one site.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    $this->editors = [1 => menuEditor(1), 2 => menuEditor(2)];
});

/** Someone who may manage the menus of the site the test is in. */
function mtEditor(): User
{
    return test()->editors[app(CurrentSite::class)->id()];
}

/** Run inside $site. */
function mtIn(int $site, Closure $callback): mixed
{
    return app(Tenancy::class)->enter($site, $callback);
}

/** A user of site 1, with the given permissions (made outside the site's context). */
function mtUser(array $permissions): User
{
    $user = User::factory()->create();
    if ($permissions !== []) {
        mtIn(1, fn () => grantRole($user, 'menu-'.count($permissions), $permissions));
        app(TeamContext::class)->forget();
    }

    return $user->fresh() ?? $user;
}

/** Why a menu change was refused, or null when it went through. */
function mtRefusal(Closure $change): ?string
{
    try {
        $change();
    } catch (MenuChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

function mtMenu(string $name = 'Menu chính', ?array $locales = null): Menu
{
    return app(SaveMenu::class)->handle(null, $name, $locales);
}

function mtItem(Menu $menu, string $label, ?MenuItem $parent = null, string $locale = 'vi'): MenuItem
{
    return app(SaveMenuItem::class)->handle(mtEditor(), $menu, null, new MenuItemData(linkType: 'dynamic', dynamicKey: 'home', labels: [$locale => $label], parent: $parent));
}

/** Labels of a menu's live tree, top level in order, each with its children: ['A' => ['A1' => []], …]. */
function mtTree(Menu $menu, ?int $parent = null): array
{
    $tree = [];
    foreach (MenuItem::query()->with('translations')->where('menu_id', $menu->id)->where('parent_id', $parent)->orderBy('position')->get() as $item) {
        $tree[(string) $item->translationFor('vi')?->label] = mtTree($menu, $item->id);
    }

    return $tree;
}

/** A Postgres error raised inside its own savepoint, so the site's transaction carries on. */
function mtDbRefuses(Closure $write): bool
{
    try {
        DB::transaction($write);
    } catch (QueryException) {
        return true;
    }

    return false;
}

// ------------------------------------------------------------------ schema

it('has the category details, the post counter, the menu trash, the follow column and the row keys', function (): void {
    $columns = collect(DB::connection('pgsql_admin')->select(
        "select table_name, column_name, data_type, is_nullable, column_default from information_schema.columns
         where table_schema = current_schema() and table_name in ('taxonomies', 'taxonomy_translations', 'menu_items', 'menu_assignments')",
    ))->keyBy(fn (object $c): string => $c->table_name.'.'.$c->column_name);

    expect($columns['taxonomy_translations.description']->data_type)->toBe('text')
        ->and($columns['taxonomy_translations.seo_title']->data_type)->toBe('character varying')
        ->and($columns['taxonomy_translations.seo_description']->data_type)->toBe('character varying')
        ->and($columns['taxonomies.cover_media_id']->is_nullable)->toBe('YES')
        ->and($columns['taxonomies.items_count']->is_nullable)->toBe('NO')
        ->and($columns['taxonomies.items_count']->column_default)->toBe('0')
        ->and($columns['taxonomies.ulid']->data_type)->toBe('character')
        ->and($columns['menu_items.ulid']->data_type)->toBe('character')
        ->and($columns['menu_items.deleted_at']->data_type)->toBe('timestamp with time zone')
        ->and($columns['menu_assignments.menu_id']->is_nullable)->toBe('YES')
        ->and($columns['menu_assignments.same_as_region']->is_nullable)->toBe('YES');

    $constraints = collect(DB::connection('pgsql_admin')->select(
        "select conname, contype, convalidated, pg_get_constraintdef(oid) as def from pg_constraint
         where conname in ('taxonomies_cover_media_id_foreign', 'taxonomies_items_count_check', 'menu_assignments_one_target_check')",
    ))->keyBy('conname');

    expect($constraints)->toHaveCount(3)
        ->and($constraints['taxonomies_cover_media_id_foreign']->def)->toContain('REFERENCES media(id) ON DELETE SET NULL')
        ->and($constraints->every(fn (object $c): bool => $c->convalidated === true))->toBeTrue();
});

it('refuses an area row with no target or two, and a negative post count', function (): void {
    mtIn(1, function (): void {
        $menu = mtMenu();

        expect(mtDbRefuses(fn () => MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi'])))->toBeTrue()
            ->and(mtDbRefuses(fn () => MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id, 'same_as_region' => 'footer'])))->toBeTrue()
            ->and(mtDbRefuses(fn () => Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'items_count' => -1])))->toBeTrue()
            ->and(MenuAssignment::query()->count())->toBe(0)
            ->and(Taxonomy::query()->count())->toBe(0);
    });
});

// ------------------------------------------------------------------ row keys

it('gives every new category and menu item its row key', function (): void {
    mtIn(1, function (): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $item = mtItem(mtMenu(), 'Trang chủ');

        expect($category->ulid)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
            ->and($item->ulid)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
            ->and($category->ulid)->not->toBe($item->ulid);
    });
});

it('fills the missing row keys site by site, trashed items included, and reports 0 the second time', function (): void {
    $rows = [];   // "table:id" → site
    foreach ([1, 2] as $site) {
        mtIn($site, function () use ($site, &$rows): void {
            $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
            $menu = mtMenu();
            $live = mtItem($menu, 'Còn');
            $trashed = mtItem($menu, 'Thùng rác');
            app(DeleteMenuItem::class)->handle(mtEditor(), $trashed);

            DB::table('taxonomies')->update(['ulid' => null, 'updated_at' => '2026-01-01 00:00:00+00']);
            DB::table('menu_items')->update(['ulid' => null, 'updated_at' => '2026-01-01 00:00:00+00']);
            $rows["taxonomies:{$category->id}"] = $site;
            $rows["menu_items:{$live->id}"] = $site;
            $rows["menu_items:{$trashed->id}"] = $site;
        });
    }

    $updates = [];
    DB::listen(function ($query) use (&$updates): void {
        if (preg_match('/^UPDATE (taxonomies|menu_items) AS t SET ulid/', $query->sql, $m) === 1) {
            $updates[] = [$m[1], $query->bindings];
        }
    });

    $this->artisan('cms:fill-ulids')
        ->expectsOutputToContain('Checked 2 site(s); filled 2 category key(s) and 4 menu item key(s).')
        ->assertSuccessful();

    // Each UPDATE names one site (its last binding) and only that site's rows.
    expect($updates)->not->toBeEmpty();
    foreach ($updates as [$table, $bindings]) {
        $site = array_pop($bindings);
        foreach (array_chunk($bindings, 2) as [$id]) {
            expect($rows["{$table}:{$id}"])->toBe($site);
        }
    }

    foreach ([1, 2] as $site) {
        mtIn($site, function () use ($site): void {
            $keys = [...Taxonomy::query()->pluck('ulid')->all(), ...MenuItem::withTrashed()->pluck('ulid')->all()];
            expect($keys)->toHaveCount(3)
                ->and(array_filter($keys, fn (?string $k): bool => $k === null || strlen($k) !== 26))->toBe([])
                ->and(array_unique($keys))->toHaveCount(3)
                ->and(DB::table('menu_items')->where('updated_at', '>', '2026-01-02')->count())->toBe(0)
                ->and(DB::table('taxonomies')->pluck('site_id')->unique()->all())->toBe([$site]);
        });
    }

    $this->artisan('cms:fill-ulids')
        ->expectsOutputToContain('filled 0 category key(s) and 0 menu item key(s).')
        ->assertSuccessful();
});

// ------------------------------------------------------------------ follow another area

it('lets the phone menu follow the main menu, then take its own menu again, the check holding each time', function (): void {
    $actor = mtUser(['menus.manage']);

    mtIn(1, function () use ($actor): void {
        $main = mtMenu();
        mtItem($main, 'Trang chủ');
        $phone = mtMenu('Menu điện thoại');
        mtItem($phone, 'Gọi ngay');
        app(AssignMenu::class)->handle($actor, 'primary', 'vi', $main);
        app(AssignMenu::class)->handle($actor, 'handheld', 'vi', $phone);

        app(FollowMenuArea::class)->handle($actor, 'handheld', 'vi', 'primary');
        $row = MenuAssignment::query()->where('region_key', 'handheld')->firstOrFail();
        expect($row->menu_id)->toBeNull()
            ->and($row->same_as_region)->toBe('primary')
            ->and(app(MenuResolver::class)->resolve('handheld', 'vi', 'vi')[0]['label'])->toBe('Trang chủ');

        app(AssignMenu::class)->handle($actor, 'handheld', 'vi', $phone);
        $row->refresh();
        expect($row->menu_id)->toBe($phone->id)
            ->and($row->same_as_region)->toBeNull()
            ->and(MenuAssignment::query()->where('region_key', 'handheld')->count())->toBe(1)
            ->and(app(MenuResolver::class)->resolve('handheld', 'vi', 'vi')[0]['label'])->toBe('Gọi ngay');
    });
});

it('refuses to follow an area the theme does not name, or to build a chain of follows', function (): void {
    $actor = mtUser(['menus.manage']);

    mtIn(1, function () use ($actor): void {
        $follow = fn (string $region, string $followed): ?string => mtRefusal(fn () => app(FollowMenuArea::class)->handle($actor, $region, 'vi', $followed));

        expect($follow('footer', 'primary'))->toBe('same_invalid')
            ->and($follow('handheld', 'footer'))->toBe('same_invalid')
            ->and($follow('handheld', 'Primary!'))->toBe('region_invalid')
            ->and($follow('handheld', str_repeat('p', 33)))->toBe('region_invalid')
            ->and(mtRefusal(fn () => app(FollowMenuArea::class)->handle($actor, 'handheld', 'en', 'primary')))->toBe('locale_invalid');

        // The main menu follows another area itself (stored by some other theme): one hop only.
        $primary = MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'same_as_region' => 'topbar']);
        expect($follow('handheld', 'primary'))->toBe('same_chain');
        $primary->delete();

        // Another area already follows the phone menu: it cannot start following in turn.
        MenuAssignment::query()->create(['region_key' => 'topbar', 'locale' => 'vi', 'same_as_region' => 'handheld']);
        expect($follow('handheld', 'primary'))->toBe('same_chain')
            ->and(MenuAssignment::query()->where('region_key', 'handheld')->exists())->toBeFalse();
    });
});

it('shows the followed area\'s menu in the same language before falling back to the default language', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $actor = mtUser(['menus.manage']);

    mtIn(1, function () use ($actor): void {
        $mainEn = mtMenu('Main', ['en']);
        mtItem($mainEn, 'Home', locale: 'en');
        $phoneVi = mtMenu('Điện thoại', ['vi']);
        mtItem($phoneVi, 'Gọi ngay');
        app(AssignMenu::class)->handle($actor, 'primary', 'en', $mainEn);
        app(AssignMenu::class)->handle($actor, 'handheld', 'vi', $phoneVi);
        app(FollowMenuArea::class)->handle($actor, 'handheld', 'en', 'primary');

        $resolver = app(MenuResolver::class);
        $trees = $resolver->resolveMany(['handheld', 'primary'], 'en', 'vi');
        expect($trees['handheld'][0]['label'])->toBe('Home')
            ->and($trees['primary'][0]['label'])->toBe('Home')
            ->and($resolver->resolve('handheld', 'vi', 'vi')[0]['label'])->toBe('Gọi ngay');

        // A stored chain is never walked: the main menu now follows another area, so the phone
        // menu in English falls back to the default language's own menu.
        MenuAssignment::query()->where('region_key', 'primary')->where('locale', 'en')->update(['menu_id' => null, 'same_as_region' => 'topbar']);
        expect($resolver->resolve('handheld', 'en', 'vi')[0]['label'])->toBe('Gọi ngay');
    });
});

it('shows the followed area\'s menu in the visitor\'s language when the follow is set in the default language only', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $actor = mtUser(['menus.manage']);

    mtIn(1, function () use ($actor): void {
        $mainVi = mtMenu('Menu chính', ['vi']);
        mtItem($mainVi, 'Trang chủ');
        $mainEn = mtMenu('Main', ['en']);
        mtItem($mainEn, 'Home', locale: 'en');
        app(AssignMenu::class)->handle($actor, 'primary', 'vi', $mainVi);
        app(AssignMenu::class)->handle($actor, 'primary', 'en', $mainEn);
        app(FollowMenuArea::class)->handle($actor, 'handheld', 'vi', 'primary');

        $resolver = app(MenuResolver::class);
        $english = $resolver->resolveMany(['handheld', 'primary'], 'en', 'vi');
        expect(array_column($english['handheld'], 'label'))->toBe(['Home'])
            ->and(array_column($english['primary'], 'label'))->toBe(['Home'])
            ->and(array_column($resolver->resolve('handheld', 'vi', 'vi'), 'label'))->toBe(['Trang chủ']);

        // One query for both areas and the area followed, the follow included.
        DB::enableQueryLog();
        DB::flushQueryLog();
        $resolver->resolveMany(['handheld'], 'en', 'vi');
        $assignmentQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'menu_assignments'));
        DB::disableQueryLog();
        expect($assignmentQueries)->toHaveCount(1);
    });
});

it('answers 403 to someone who may not manage menus, on both assignment paths', function (): void {
    $nobody = mtUser(['entries.view']);

    mtIn(1, function () use ($nobody): void {
        $menu = mtMenu();
        $statuses = [];
        foreach ([
            fn () => app(AssignMenu::class)->handle($nobody, 'primary', 'vi', $menu),
            fn () => app(FollowMenuArea::class)->handle($nobody, 'handheld', 'vi', 'primary'),
        ] as $write) {
            try {
                $write();
                $statuses[] = 200;
            } catch (AuthorizationException $e) {
                $statuses[] = app(ExceptionHandler::class)->render(request(), $e)->getStatusCode();
            }
        }

        expect($statuses)->toBe([403, 403])
            ->and(MenuAssignment::query()->count())->toBe(0);
    });
});

it('answers 403 to someone who may not manage menus, on every item write', function (): void {
    $nobody = mtUser(['entries.view']);

    mtIn(1, function () use ($nobody): void {
        $menu = mtMenu();
        $live = mtItem($menu, 'Còn');
        $trashed = mtItem($menu, 'Thùng rác');
        app(DeleteMenuItem::class)->handle(mtEditor(), $trashed);
        $statuses = [];
        foreach ([
            fn () => app(SaveMenuItem::class)->handle($nobody, $menu, null, new MenuItemData(linkType: 'dynamic', dynamicKey: 'home', labels: ['vi' => 'Mới'])),
            fn () => app(SaveMenuItem::class)->handle($nobody, $menu, $live, new MenuItemData(linkType: 'url', url: '/lien-he')),
            fn () => app(MoveMenuItem::class)->handle($nobody, $live, null, 1),
            fn () => app(DeleteMenuItem::class)->handle($nobody, $live),
            fn () => app(RestoreMenuItem::class)->handle($nobody, $trashed),
        ] as $write) {
            try {
                $write();
                $statuses[] = 200;
            } catch (AuthorizationException $e) {
                $statuses[] = app(ExceptionHandler::class)->render(request(), $e)->getStatusCode();
            }
        }

        expect($statuses)->toBe([403, 403, 403, 403, 403])
            ->and(mtTree($menu))->toBe(['Còn' => []])
            ->and($live->refresh()->link_type)->toBe('dynamic')
            ->and(MenuItem::onlyTrashed()->pluck('id')->all())->toBe([$trashed->id]);
    });
});

// ------------------------------------------------------------------ trash

it('moves an item with its whole branch to the trash, and the website no longer shows them', function (): void {
    $actor = mtUser(['menus.manage']);

    mtIn(1, function () use ($actor): void {
        $menu = mtMenu();
        $parent = mtItem($menu, 'Cha');
        $child = mtItem($menu, 'Con', $parent);
        mtItem($menu, 'Cháu', $child);
        mtItem($menu, 'Bên cạnh');
        app(AssignMenu::class)->handle($actor, 'primary', 'vi', $menu);

        expect(app(DeleteMenuItem::class)->handle(mtEditor(), $parent))->toBe(3);

        $trashed = MenuItem::onlyTrashed()->get();
        expect($trashed)->toHaveCount(3)
            ->and($trashed->pluck('deleted_at')->map(fn ($at) => $at->toIso8601String())->unique())->toHaveCount(1)
            ->and($trashed->pluck('parent_id')->filter()->all())->not->toBeEmpty()   // the branch keeps its shape
            ->and(mtTree($menu))->toBe(['Bên cạnh' => []])
            ->and(array_column(app(MenuResolver::class)->resolve('primary', 'vi', 'vi'), 'label'))->toBe(['Bên cạnh']);
    });
});

it('restores an item with the branch removed with it, at its old place', function (): void {
    mtIn(1, function (): void {
        $menu = mtMenu();
        mtItem($menu, 'Đầu');
        $parent = mtItem($menu, 'Cha');
        $child = mtItem($menu, 'Con', $parent);
        mtItem($menu, 'Cháu', $child);
        mtItem($menu, 'Cuối');

        app(DeleteMenuItem::class)->handle(mtEditor(), $parent);
        expect(mtTree($menu))->toBe(['Đầu' => [], 'Cuối' => []]);

        expect(app(RestoreMenuItem::class)->handle(mtEditor(), $parent))->toBe(3)
            ->and(mtTree($menu))->toBe(['Đầu' => [], 'Cha' => ['Con' => ['Cháu' => []]], 'Cuối' => []])
            ->and(MenuItem::query()->where('menu_id', $menu->id)->whereNull('parent_id')->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2])
            ->and(MenuItem::onlyTrashed()->count())->toBe(0)
            ->and(mtRefusal(fn () => app(RestoreMenuItem::class)->handle(mtEditor(), $parent)))->toBe('item_invalid');
    });
});

it('restores a sub-item whose parent is in the trash, or gone for good, at the end of the top level', function (): void {
    mtIn(1, function (): void {
        $menu = mtMenu();
        $parent = mtItem($menu, 'Cha');
        $first = mtItem($menu, 'Con 1', $parent);
        mtItem($menu, 'Con 2', $parent);
        mtItem($menu, 'Bên cạnh');

        // One sub-item removed on its own first, then the parent with what is left under it.
        app(DeleteMenuItem::class)->handle(mtEditor(), $first);
        MenuItem::onlyTrashed()->whereKey($first->id)->update(['deleted_at' => now()->subMinute()]);
        app(DeleteMenuItem::class)->handle(mtEditor(), $parent);

        expect(app(RestoreMenuItem::class)->handle(mtEditor(), $first))->toBe(1)
            ->and(mtTree($menu))->toBe(['Bên cạnh' => [], 'Con 1' => []])
            ->and($first->refresh()->parent_id)->toBeNull();

        // The parent comes back with its own removal only; the sub-item restored earlier stays put.
        expect(app(RestoreMenuItem::class)->handle(mtEditor(), $parent))->toBe(2)
            ->and(mtTree($menu))->toBe(['Cha' => ['Con 2' => []], 'Bên cạnh' => [], 'Con 1' => []]);

        // A parent deleted for good: its trashed sub-item comes back at the top level.
        $other = mtItem($menu, 'Cha khác');
        $orphan = mtItem($menu, 'Con mồ côi', $other);
        app(DeleteMenuItem::class)->handle(mtEditor(), $orphan);
        MenuItem::query()->whereKey($other->id)->forceDelete();

        expect(app(RestoreMenuItem::class)->handle(mtEditor(), $orphan))->toBe(1)
            ->and(array_keys(mtTree($menu)))->toEqualCanonicalizing(['Cha', 'Bên cạnh', 'Con 1', 'Con mồ côi'])
            ->and(MenuItem::query()->where('menu_id', $menu->id)->whereNull('parent_id')->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2, 3]);
    });
});

it('refuses to add a sub-item under an item another editor has trashed, or to move or save a trashed item', function (): void {
    $other = menuEditor(1);

    mtIn(1, function () use ($other): void {
        $menu = mtMenu();
        $parent = mtItem($menu, 'Cha');
        $live = mtItem($menu, 'Bên cạnh');
        $gone = mtItem($menu, 'Đã xoá');

        // The screen still holds both items; another editor moves them to the trash meanwhile.
        app(DeleteMenuItem::class)->handle($other, $parent);
        app(DeleteMenuItem::class)->handle($other, $gone);

        expect(mtRefusal(fn () => mtItem($menu, 'Con', $parent)))->toBe('parent_invalid')
            ->and(mtRefusal(fn () => app(MoveMenuItem::class)->handle(mtEditor(), $live, $parent, 0)))->toBe('parent_invalid')
            ->and(mtRefusal(fn () => app(MoveMenuItem::class)->handle(mtEditor(), $gone, null, 0)))->toBe('item_invalid')
            ->and(mtRefusal(fn () => app(SaveMenuItem::class)->handle(mtEditor(), $menu, $gone, new MenuItemData(linkType: 'dynamic', dynamicKey: 'home', labels: ['vi' => 'Sửa muộn']))))->toBe('item_invalid')
            ->and(MenuItem::withTrashed()->whereKey($gone->id)->first()?->translationFor('vi')?->label)->toBe('Đã xoá')
            ->and(mtTree($menu))->toBe(['Bên cạnh' => []])
            ->and(MenuItem::withTrashed()->count())->toBe(3);
    });
});

it('purges items trashed more than 30 days ago with their branch, keeps newer ones, site by site', function (): void {
    $made = [];
    foreach ([1, 2] as $site) {
        $made[$site] = mtIn($site, function (): array {
            $menu = mtMenu();
            $old = mtItem($menu, 'Cũ');
            $oldChild = mtItem($menu, 'Cũ con', $old);
            $recent = mtItem($menu, 'Mới');
            $live = mtItem($menu, 'Còn');
            app(DeleteMenuItem::class)->handle(mtEditor(), $old);
            app(DeleteMenuItem::class)->handle(mtEditor(), $recent);
            MenuItem::onlyTrashed()->whereKey([$old->id, $oldChild->id])->update(['deleted_at' => now()->subDays(31)]);
            MenuItem::onlyTrashed()->whereKey($recent->id)->update(['deleted_at' => now()->subDays(29)]);

            return ['old' => $old->id, 'old_child' => $oldChild->id, 'recent' => $recent->id, 'live' => $live->id];
        });
    }
    // Site 2's old items are only a few days old there: the window is per item, not per run.
    mtIn(2, fn () => MenuItem::onlyTrashed()->whereKey([$made[2]['old'], $made[2]['old_child']])->update(['deleted_at' => now()->subDays(3)]));

    $this->artisan('menus:purge-trash')->expectsOutputToContain('Purged 2 menu item(s)')->assertSuccessful();

    mtIn(1, function () use ($made): void {
        expect(MenuItem::withTrashed()->pluck('id')->sort()->values()->all())->toBe([$made[1]['recent'], $made[1]['live']])
            ->and(DB::table('menu_item_translations')->whereIn('menu_item_id', [$made[1]['old'], $made[1]['old_child']])->count())->toBe(0);
    });
    mtIn(2, function (): void {
        expect(MenuItem::withTrashed()->count())->toBe(4)
            ->and(MenuItem::onlyTrashed()->count())->toBe(3);
    });
});

it('never reaches another site\'s trash: restore from site A refuses site B\'s item', function (): void {
    $foreign = mtIn(2, function (): MenuItem {
        $item = mtItem(mtMenu(), 'Của B');
        app(DeleteMenuItem::class)->handle(mtEditor(), $item);

        return MenuItem::onlyTrashed()->findOrFail($item->id);
    });

    mtIn(1, function () use ($foreign): void {
        expect(mtRefusal(fn () => app(RestoreMenuItem::class)->handle(mtEditor(), $foreign)))->toBe('item_invalid')
            ->and(MenuItem::onlyTrashed()->count())->toBe(0);
    });

    mtIn(2, fn () => expect(MenuItem::onlyTrashed()->whereKey($foreign->id)->exists())->toBeTrue());
});
