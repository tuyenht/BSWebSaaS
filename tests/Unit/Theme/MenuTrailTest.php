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
