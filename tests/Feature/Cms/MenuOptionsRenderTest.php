<?php

use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/*
 * What the website draws from a menu's display options: hidden items vanish with their branch,
 * a badge disappears after its end date, a special link appears only when the site has the detail
 * it leads to, and the phone menu shows its own menu, else the main one. Item classes and the
 * badge reach the rich header's markup.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
});

function moIn(Closure $callback): mixed
{
    return app(Tenancy::class)->enter(1, $callback);
}

function moMenu(string $name, string $area): Menu
{
    $menu = Menu::query()->create(['name' => $name, 'handle' => 'mo-'.mb_strtolower(str_replace(' ', '-', $name))]);
    MenuAssignment::query()->updateOrCreate(['region_key' => $area, 'locale' => 'vi'], ['menu_id' => $menu->id, 'same_as_region' => null]);

    return $menu;
}

/** @param array<string, mixed> $attributes */
function moItem(Menu $menu, string $label, array $attributes = [], ?MenuItem $parent = null): MenuItem
{
    $position = MenuItem::query()->where('menu_id', $menu->id)->where('parent_id', $parent?->id)->count();
    $item = MenuItem::query()->create([
        'menu_id' => $menu->id, 'parent_id' => $parent?->id, 'position' => $position,
        'link_type' => 'url', 'url' => 'https://example.com/'.$position, ...$attributes,
    ]);
    $item->translations()->create(['locale' => 'vi', 'label' => $label]);

    return $item;
}

/** @param array<string, mixed> $contact */
function moResolve(string $area = 'primary', array $contact = []): array
{
    return moIn(fn () => app(MenuResolver::class)->resolve($area, 'vi', 'vi', '/', $contact));
}

/** @return list<string> */
function moLabels(array $nodes): array
{
    return array_map(fn (array $node): string => $node['label'], $nodes);
}

it('leaves out a hidden item together with everything under it', function (): void {
    moIn(function (): void {
        $menu = moMenu('Menu chính', 'primary');
        moItem($menu, 'Trang chủ');
        $hidden = moItem($menu, 'Dịch vụ', ['meta' => ['hidden' => true]]);
        moItem($menu, 'Gia công', [], $hidden);
        $shown = moItem($menu, 'ồ Liên hệ ộ');
        moItem($menu, 'Bản đồ', ['meta' => ['hidden' => true]], $shown);
    });

    $nodes = moResolve();

    expect(moLabels($nodes))->toBe(['Trang chủ', 'ồ Liên hệ ộ'])
        ->and($nodes[1]['children'])->toBe([]);
});

it('shows a badge until the end of its last day, then drops it', function (): void {
    $this->travelTo(now()->setDate(2026, 11, 5)->setTime(10, 0));
    moIn(function (): void {
        $menu = moMenu('Menu chính', 'primary');
        moItem($menu, 'Mới về', ['meta' => ['badge' => 'new', 'badge_until' => '2026-11-05T16:59:59Z']]);
        moItem($menu, 'Hết hạn', ['meta' => ['badge' => 'sale', 'badge_until' => '2026-11-04T16:59:59Z']]);
        moItem($menu, 'Mãi mãi', ['meta' => ['badge' => 'hot']]);
    });

    expect(array_column(moResolve(), 'badge', 'label'))->toBe(['Mới về' => 'new', 'Hết hạn' => null, 'Mãi mãi' => 'hot']);

    $this->travelTo(now()->setDate(2026, 11, 5)->setTime(17, 0));   // midnight in Hà Nội
    expect(array_column(moResolve(), 'badge', 'label'))->toBe(['Mới về' => null, 'Hết hạn' => null, 'Mãi mãi' => 'hot']);
});

it('shows a special link only when the site has the detail it leads to', function (): void {
    moIn(function (): void {
        $menu = moMenu('Menu chính', 'primary');
        foreach (['home' => 'Trang chủ', 'group' => 'ồ Nhóm ộ', 'call' => 'Gọi', 'zalo' => 'Zalo', 'mail' => 'Thư', 'map' => 'Chỉ đường', 'cart' => 'Giỏ hàng', 'search' => 'Tìm kiếm'] as $key => $label) {
            moItem($menu, $label, ['link_type' => 'dynamic', 'url' => null, 'dynamic_key' => $key]);
        }
    });

    // Nothing known about the site: only the home link and the bare label stay; the cart and the
    // search have no page until their module provides one.
    expect(array_column(moResolve(), 'url', 'label'))->toBe(['Trang chủ' => '/', 'ồ Nhóm ộ' => '#']);

    $full = array_column(moResolve(contact: [
        'phone' => '024 3333 4444', 'hotline' => '0901 234 567', 'email' => 'lienhe@mau.test',
        'address' => 'Số 1 Đường Thử, Hà Nội', 'social_links' => ['zalo' => 'https://zalo.me/0901234567'],
    ]), 'url', 'label');

    expect($full)->toBe([
        'Trang chủ' => '/',
        'ồ Nhóm ộ' => '#',
        'Gọi' => 'tel:0901234567',
        'Zalo' => 'https://zalo.me/0901234567',
        'Thư' => 'mailto:lienhe@mau.test',
        'Chỉ đường' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode('Số 1 Đường Thử, Hà Nội'),
    ]);

    // A Zalo link to anywhere else is not trusted.
    expect(array_column(moResolve(contact: ['social_links' => ['zalo' => 'https://evil.example/zalo.me/x']]), 'url', 'label'))
        ->not->toHaveKey('Zalo');
});

it('passes the display options to the theme in the page\'s language', function (): void {
    moIn(function (): void {
        $menu = moMenu('Menu chính', 'primary');
        moItem($menu, 'Sản phẩm', ['meta' => [
            'icon' => 'phone', 'cta' => true, 'device' => 'desktop', 'mega' => ['type' => 'columns', 'columns' => 3],
            'description' => ['vi' => 'ồ Mô tả ộ', 'en' => 'About'],
        ]]);
    });

    $node = moResolve()[0];

    expect([$node['icon'], $node['description'], $node['cta'], $node['mega'], $node['device']])
        ->toBe(['phone', 'ồ Mô tả ộ', true, 3, 'desktop']);
});

function moRichHeader(): void
{
    seedHomepage(1, 'Trang chủ');
    moIn(fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'header'], ['value' => ['sticky' => false]]));
}

/** The phone menu panel of a rendered page. */
function moDrawer(string $html): string
{
    preg_match('~<nav class="sf-nav sf-nav--mob[^"]*".*?</nav>~s', $html, $drawer);

    return $drawer[0] ?? '';
}

/** The desktop menu of a rendered page. */
function moDesk(string $html): string
{
    preg_match('~<nav[^>]*class="sf-nav sf-nav--desk".*?</nav>~s', $html, $desk);

    return $desk[0] ?? '';
}

it('fills the phone menu with its own menu when it has one', function (): void {
    moRichHeader();
    moIn(function (): void {
        moItem(moMenu('Menu chính', 'primary'), 'Mục máy tính');
        moItem(moMenu('Menu điện thoại', 'handheld'), 'ồ Mục điện thoại ộ');
    });

    $html = $this->get('http://a.test/')->assertOk()->getContent();

    expect(moDrawer($html))->toContain('ồ Mục điện thoại ộ')->not->toContain('Mục máy tính')
        ->and(moDesk($html))->toContain('Mục máy tính')->not->toContain('Mục điện thoại');
});

it('fills the phone menu with the main menu when it follows it or has none', function (bool $follows): void {
    moRichHeader();
    moIn(function () use ($follows): void {
        moItem(moMenu('Menu chính', 'primary'), 'ồ Mục chung ộ');
        if ($follows) {
            MenuAssignment::query()->create(['region_key' => 'handheld', 'locale' => 'vi', 'menu_id' => null, 'same_as_region' => 'primary']);
        }
    });

    $html = $this->get('http://a.test/')->assertOk()->getContent();

    expect(moDrawer($html))->toContain('ồ Mục chung ộ');
})->with(['follows the main menu' => true, 'never set up' => false]);

it('marks device-only items and draws badges and button-style links in the header', function (): void {
    moRichHeader();
    moIn(function (): void {
        $menu = moMenu('Menu chính', 'primary');
        moItem($menu, 'Chỉ máy tính', ['meta' => ['device' => 'desktop']]);
        moItem($menu, 'Chỉ điện thoại', ['meta' => ['device' => 'phone']]);
        moItem($menu, 'Khuyến mãi', ['meta' => ['badge' => 'sale', 'cta' => true]]);
    });

    $desk = moDesk($this->get('http://a.test/')->assertOk()->getContent());

    expect($desk)->toMatch('~<li class="[^"]*sf-only-desk[^"]*">\s*<a[^>]*>\s*(?:<span>)?Chỉ máy tính~u')
        ->toMatch('~<li class="[^"]*sf-only-mob[^"]*">\s*<a[^>]*>\s*(?:<span>)?Chỉ điện thoại~u')
        ->toContain('sf-nav__link--cta')
        ->toContain('<span class="sf-menu__badge">Giảm giá</span>');
});

it('picks an automatic icon for top-level rows only, never over an own icon, a turned-off icon or a button row', function (): void {
    moIn(function (): void {
        $menu = moMenu('Menu chính', 'primary');
        $parent = moItem($menu, 'Tin tức');
        moItem($menu, 'Giới thiệu', [], $parent);
        moItem($menu, 'Sản phẩm', ['meta' => ['icon' => 'box']]);
        moItem($menu, 'Liên hệ', ['meta' => ['icon' => 'none']]);
        moItem($menu, 'Khuyến mãi', ['meta' => ['cta' => true]]);
        moItem($menu, 'Xyzzy');
        moItem($menu, 'Bất kỳ', ['link_type' => 'dynamic', 'dynamic_key' => 'home', 'url' => null]);
    });

    $nodes = moResolve();
    $by = fn (string $label): array => collect($nodes)->firstWhere('label', $label);

    expect($by('Tin tức')['auto_icon'])->toBe('newspaper')
        ->and($by('Tin tức')['icon'])->toBeNull()
        ->and($by('Tin tức')['children'][0]['auto_icon'])->toBeNull()
        ->and($by('Sản phẩm')['icon'])->toBe('box')->and($by('Sản phẩm')['auto_icon'])->toBeNull()
        ->and($by('Liên hệ')['icon'])->toBeNull()->and($by('Liên hệ')['auto_icon'])->toBeNull()
        ->and($by('Khuyến mãi')['auto_icon'])->toBeNull()
        ->and($by('Xyzzy')['auto_icon'])->toBeNull()
        ->and($by('Bất kỳ')['auto_icon'])->toBe('home');
});

it('draws the automatic icon in the phone drawer and not in the desktop bar', function (): void {
    moRichHeader();
    moIn(function (): void {
        $menu = moMenu('Menu chính', 'primary');
        moItem($menu, 'Tin tức');
        moItem($menu, 'Giới thiệu', ['meta' => ['icon' => 'none']]);
    });

    $html = $this->get('http://a.test/')->assertOk()->getContent();
    $drawer = moDrawer($html);

    expect($drawer)->toContain('sf-nav--icons')
        ->toMatch('~<a[^>]*class="sf-nav__link[^"]*"[^>]*>\\s*<svg class="sf-icon sf-nav__ic"[^>]*>.*?</svg><span class="sf-nav__t">Tin tức</span>~s')
        ->toMatch('~<a[^>]*class="sf-nav__link[^"]*"[^>]*>\\s*<span class="sf-nav__t">Giới thiệu</span>~s')
        ->and(moDesk($html))->toContain('Tin tức')->not->toContain('<svg');
});
