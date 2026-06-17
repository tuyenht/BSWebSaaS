<?php

use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Navigation\MenuResolver;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * ③a — MenuResolver (design.md §13): resolve a region's menu (via menu_assignments) into a
 * primitive tree — batched URL/label lookup (no N+1), orphan-safe, multi-level, label
 * fallback to the target title, external-URL sanitisation, active-path marking, and a
 * default-locale fallback when a locale has no assignment. All run inside the tenant context.
 */

beforeEach(fn () => seedTenancy());

/**
 * Build An Vinh-style navigation in site 1: a news category at /tin-tuc + a primary menu
 * (Trang chủ [dynamic→/] + Tin tức [taxonomy]) assigned to the primary region for 'vi'.
 *
 * @return array{news: int, menu: int, newsItem: int}
 */
function seedNavFixture(): array
{
    return app(Tenancy::class)->enter(1, function () {
        $news = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $news->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/tin-tuc', 'taxonomy_id' => $news->id]);

        $menu = Menu::query()->create(['handle' => 'main-vi', 'name' => 'Menu chính']);

        $home = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 0]);
        $home->translations()->create(['locale' => 'vi', 'label' => 'Trang chủ']);

        $newsItem = MenuItem::query()->create([
            'menu_id' => $menu->id, 'link_type' => 'taxonomy', 'routable_type' => 'taxonomy', 'routable_id' => $news->id, 'position' => 1,
        ]);
        $newsItem->translations()->create(['locale' => 'vi', 'label' => 'Tin tức']);

        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'vi', 'menu_id' => $menu->id]);

        return ['news' => $news->id, 'menu' => $menu->id, 'newsItem' => $newsItem->id];
    });
}

it('resolves the primary menu to home + category links', function () {
    seedNavFixture();

    $tree = app(Tenancy::class)->enter(1, fn () => app(MenuResolver::class)->resolve('primary', 'vi', 'vi', '/'));

    expect($tree)->toHaveCount(2)
        ->and($tree[0]['label'])->toBe('Trang chủ')
        ->and($tree[0]['url'])->toBe('/')
        ->and($tree[0]['active'])->toBeTrue()
        ->and($tree[1]['label'])->toBe('Tin tức')
        ->and($tree[1]['url'])->toBe('/tin-tuc')
        ->and($tree[1]['active'])->toBeFalse();
});

it('marks the active item by current path', function () {
    seedNavFixture();

    $tree = app(Tenancy::class)->enter(1, fn () => app(MenuResolver::class)->resolve('primary', 'vi', 'vi', '/tin-tuc'));

    expect(collect($tree)->firstWhere('url', '/')['active'])->toBeFalse()
        ->and(collect($tree)->firstWhere('url', '/tin-tuc')['active'])->toBeTrue();
});

it('nests children under their parent (multi-level)', function () {
    $ids = seedNavFixture();

    $tree = app(Tenancy::class)->enter(1, function () use ($ids) {
        $child = MenuItem::query()->create([
            'menu_id' => $ids['menu'], 'parent_id' => $ids['newsItem'],
            'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 0,
        ]);
        $child->translations()->create(['locale' => 'vi', 'label' => 'Mục con']);

        return app(MenuResolver::class)->resolve('primary', 'vi', 'vi', '/');
    });

    $news = collect($tree)->firstWhere('url', '/tin-tuc');
    expect($news['children'])->toHaveCount(1)
        ->and($news['children'][0]['label'])->toBe('Mục con');
});

it('omits an item whose target has no route (orphan-safe)', function () {
    $ids = seedNavFixture();

    $tree = app(Tenancy::class)->enter(1, function () use ($ids) {
        $orphan = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $orphan->translations()->create(['locale' => 'vi', 'name' => 'Mồ côi', 'slug' => 'mo-coi']);
        // No route → unresolvable.
        $item = MenuItem::query()->create([
            'menu_id' => $ids['menu'], 'link_type' => 'taxonomy', 'routable_type' => 'taxonomy', 'routable_id' => $orphan->id, 'position' => 2,
        ]);
        $item->translations()->create(['locale' => 'vi', 'label' => 'Mồ côi']);

        return app(MenuResolver::class)->resolve('primary', 'vi', 'vi', '/');
    });

    expect($tree)->toHaveCount(2)
        ->and(collect($tree)->pluck('label')->all())->not->toContain('Mồ côi');
});

it('falls back to the target title when an item has no label', function () {
    $ids = seedNavFixture();

    $tree = app(Tenancy::class)->enter(1, function () use ($ids) {
        $svc = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $svc->translations()->create(['locale' => 'vi', 'name' => 'Dịch vụ', 'slug' => 'dich-vu']);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/dich-vu', 'taxonomy_id' => $svc->id]);
        // No translation row → label must fall back to the taxonomy's translated name.
        MenuItem::query()->create([
            'menu_id' => $ids['menu'], 'link_type' => 'taxonomy', 'routable_type' => 'taxonomy', 'routable_id' => $svc->id, 'position' => 2,
        ]);

        return app(MenuResolver::class)->resolve('primary', 'vi', 'vi', '/');
    });

    expect(collect($tree)->firstWhere('url', '/dich-vu')['label'])->toBe('Dịch vụ');
});

it('passes a safe external url and marks _blank links rel=noopener', function () {
    $ids = seedNavFixture();

    $tree = app(Tenancy::class)->enter(1, function () use ($ids) {
        $ext = MenuItem::query()->create([
            'menu_id' => $ids['menu'], 'link_type' => 'url', 'url' => 'https://baoson.test', 'position' => 2,
            'meta' => ['target' => '_blank'],
        ]);
        $ext->translations()->create(['locale' => 'vi', 'label' => 'Đối tác']);

        return app(MenuResolver::class)->resolve('primary', 'vi', 'vi', '/');
    });

    $node = collect($tree)->firstWhere('label', 'Đối tác');
    expect($node['url'])->toBe('https://baoson.test')
        ->and($node['attrs']['target'])->toBe('_blank')
        ->and($node['attrs']['rel'])->toBe('noopener noreferrer');
});

it('drops a url item with an unsafe scheme', function () {
    $ids = seedNavFixture();

    $tree = app(Tenancy::class)->enter(1, function () use ($ids) {
        $bad = MenuItem::query()->create(['menu_id' => $ids['menu'], 'link_type' => 'url', 'url' => 'javascript:alert(1)', 'position' => 2]);
        $bad->translations()->create(['locale' => 'vi', 'label' => 'XSS']);

        return app(MenuResolver::class)->resolve('primary', 'vi', 'vi', '/');
    });

    expect(collect($tree)->pluck('label')->all())->not->toContain('XSS');
});

it('falls back to the default-locale menu when the locale has no assignment', function () {
    seedNavFixture();   // only a 'vi' assignment exists

    $tree = app(Tenancy::class)->enter(1, fn () => app(MenuResolver::class)->resolve('primary', 'en', 'vi', '/'));

    // The vi menu is rendered (in vi) rather than a dead-end empty nav.
    expect($tree)->toHaveCount(2)
        ->and($tree[0]['url'])->toBe('/')
        ->and($tree[0]['label'])->toBe('Trang chủ');
});

it('returns an empty menu when the region has no assignment', function () {
    seedNavFixture();

    $tree = app(Tenancy::class)->enter(1, fn () => app(MenuResolver::class)->resolve('footer', 'vi', 'vi', '/'));

    expect($tree)->toBe([]);
});

it('terminates on a parent_id cycle (no infinite recursion)', function () {
    $ids = seedNavFixture();

    $tree = app(Tenancy::class)->enter(1, function () use ($ids) {
        $a = MenuItem::query()->create(['menu_id' => $ids['menu'], 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 5]);
        $b = MenuItem::query()->create(['menu_id' => $ids['menu'], 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 6, 'parent_id' => $a->id]);
        $a->update(['parent_id' => $b->id]);   // A↔B cycle: neither is a root

        return app(MenuResolver::class)->resolve('primary', 'vi', 'vi', '/');
    });

    expect($tree)->toHaveCount(2);   // only the original home + news roots; cyclic pair excluded
});

it('resolves the whole tree within a fixed query budget (no N+1)', function () {
    $ids = seedNavFixture();

    app(Tenancy::class)->enter(1, function () use ($ids) {
        // Extra siblings must NOT add per-item queries (batched URL + label resolution).
        for ($i = 0; $i < 5; $i++) {
            $item = MenuItem::query()->create(['menu_id' => $ids['menu'], 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 10 + $i]);
            $item->translations()->create(['locale' => 'vi', 'label' => "X{$i}"]);
        }

        $count = countQueries(fn () => app(MenuResolver::class)->resolve('primary', 'vi', 'vi', '/'));

        expect($count)->toBeLessThanOrEqual(5);   // menu + items + translations + routes
    });
});

it('prefixes resolved URLs for a non-default-locale menu (via PathBuilder)', function () {
    $ids = seedNavFixture();   // vi menu + news taxonomy + vi route /tin-tuc

    $tree = app(Tenancy::class)->enter(1, function () use ($ids) {
        // An 'en' menu for the same news category: en route + en assignment. Site default
        // stays 'vi', so the resolved 'en' URLs must be /en-prefixed (through publicUrl).
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/tin-tuc', 'taxonomy_id' => $ids['news']]);
        $menu = Menu::query()->create(['handle' => 'main-en', 'name' => 'Menu EN']);
        $home = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'dynamic', 'dynamic_key' => 'home', 'position' => 0]);
        $home->translations()->create(['locale' => 'en', 'label' => 'Home']);
        $news = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'taxonomy', 'routable_type' => 'taxonomy', 'routable_id' => $ids['news'], 'position' => 1]);
        $news->translations()->create(['locale' => 'en', 'label' => 'News']);
        MenuAssignment::query()->create(['region_key' => 'primary', 'locale' => 'en', 'menu_id' => $menu->id]);

        return app(MenuResolver::class)->resolve('primary', 'en', 'vi', '/en');
    });

    expect(collect($tree)->firstWhere('label', 'News')['url'])->toBe('/en/tin-tuc')
        ->and(collect($tree)->firstWhere('label', 'Home')['url'])->toBe('/en');
});
