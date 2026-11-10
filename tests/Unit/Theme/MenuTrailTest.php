<?php

use App\Modules\Theme\Chrome\StorefrontChrome;

/*
 * The menu items a page sits under: the levels of its breadcrumb between the home page and the page
 * itself mark the menu items that link to them, so a post or a project lights up its section.
 */

function menuTrailFixture(): array
{
    return [
        ['label' => 'Trang chủ', 'url' => '/', 'active' => false, 'children' => []],
        ['label' => 'Dự án', 'url' => '/du-an', 'active' => false, 'children' => []],
        ['label' => 'Tin tức', 'url' => '/tin-tuc', 'active' => false, 'children' => [
            ['label' => 'Sự kiện', 'url' => '/su-kien', 'active' => false, 'children' => []],
        ]],
    ];
}

it('marks the menu items that link to a level above the page, in both menus', function (): void {
    $extra = StorefrontChrome::withTrail(['menu' => menuTrailFixture(), 'mobileMenu' => menuTrailFixture(), 'breadcrumb' => [
        ['name' => 'Trang chủ', 'url' => 'https://x.test/', 'href' => '/'],
        ['name' => 'Dự án', 'url' => 'https://x.test/du-an', 'href' => '/du-an'],
        ['name' => 'Hệ thống cấp phôi', 'url' => 'https://x.test/he-thong', 'href' => '/he-thong'],
    ]]);

    foreach (['menu', 'mobileMenu'] as $key) {
        expect(array_map(StorefrontChrome::inTrail(...), $extra[$key]))->toBe([false, true, false]);
    }
});

it('marks a parent through a child on the trail, and never the home page or the page itself', function (): void {
    $extra = StorefrontChrome::withTrail(['menu' => menuTrailFixture(), 'breadcrumb' => [
        ['name' => 'Trang chủ', 'href' => '/'],
        ['name' => 'Sự kiện', 'href' => '/su-kien'],
        ['name' => 'Dự án', 'href' => '/du-an'],
    ]]);

    expect(array_map(StorefrontChrome::inTrail(...), $extra['menu']))->toBe([false, false, true])
        ->and($extra['menu'][2]['children'][0]['trail'])->toBeTrue()
        ->and($extra['menu'][1])->not->toHaveKey('trail');
});

it('leaves the menus alone without a breadcrumb, and never marks the current item', function (): void {
    $extra = ['menu' => menuTrailFixture(), 'breadcrumb' => []];
    expect(StorefrontChrome::withTrail($extra))->toBe($extra)
        ->and(StorefrontChrome::withTrail(['menu' => menuTrailFixture()]))->toBe(['menu' => menuTrailFixture()]);

    $menu = menuTrailFixture();
    $menu[1]['active'] = true;
    $out = StorefrontChrome::withTrail(['menu' => $menu, 'breadcrumb' => [['href' => '/'], ['href' => '/du-an'], ['href' => '/x']]]);
    expect($out['menu'][1])->not->toHaveKey('trail');
});

/** An Vinh's desktop menu: nine items, one heading-only parent and two parents with a page. */
function menuWidthFixture(): array
{
    $leaf = fn (string $label): array => ['label' => $label, 'url' => '/'.md5($label), 'active' => false, 'children' => []];
    $kids = [$leaf('Một'), $leaf('Hai')];

    return [
        $leaf('Trang chủ'),
        ['label' => 'Giới thiệu', 'url' => '#', 'active' => false, 'children' => $kids],
        ['label' => 'Năng lực', 'url' => '/nang-luc', 'active' => false, 'children' => $kids],
        ['label' => 'Sản phẩm', 'url' => '/san-pham', 'active' => false, 'children' => $kids],
        $leaf('Dự án'), $leaf('Đối tác'), $leaf('Tin tức'), $leaf('Tuyển dụng'), $leaf('Liên hệ'),
    ];
}

it('gives a menu too wide for a 1024px laptop the xl switch, and keeps a short one at lg', function (): void {
    // An Vinh's nine items measure 837px; the room beside a 200px logo at 1024px is 698px.
    $menu = menuWidthFixture();

    expect(StorefrontChrome::menuNeedsXl($menu))->toBeTrue()
        // its first five (500px measured) fit
        ->and(StorefrontChrome::menuNeedsXl(array_slice($menu, 0, 5)))->toBeFalse()
        // six long labels do not fit either, though they are fewer
        ->and(StorefrontChrome::menuNeedsXl(array_map(fn (int $i): array => ['label' => 'Gia công cơ khí chính xác '.$i, 'url' => '/'.$i, 'children' => []], range(1, 6))))->toBeTrue()
        ->and(StorefrontChrome::menuNeedsXl([]))->toBeFalse()
        ->and(StorefrontChrome::menuNeedsXl(null))->toBeFalse();
});

it('leaves out of the width what a laptop does not draw: phone-only rows and links to nowhere', function (): void {
    $menu = array_slice(menuWidthFixture(), 0, 5);
    $hidden = [
        ['label' => 'Chỉ trên điện thoại rất dài', 'url' => '/a', 'device' => 'phone', 'children' => []],
        ['label' => 'Chưa có trang nhưng nhãn dài', 'url' => '#', 'children' => []],
        ['label' => 'Chỉ trên điện thoại cũng dài', 'url' => '/b', 'device' => 'phone', 'children' => []],
    ];
    $shown = array_map(fn (array $item): array => ['device' => 'desktop'] + $item, $hidden);
    $shown[1]['url'] = '/c';

    expect(StorefrontChrome::menuNeedsXl([...$menu, ...$hidden]))->toBeFalse()
        ->and(StorefrontChrome::menuNeedsXl([...$menu, ...$shown]))->toBeTrue();
});
