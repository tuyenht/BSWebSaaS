<?php

use App\Modules\Cms\Navigation\MenuIconGuess;
use App\Modules\Theme\Chrome\StorefrontIcons;

/*
 * The icon the phone menu picks by itself for a top-level row with none chosen: the kind of a special link
 * first, then the words of the label in any language the site ships, else nothing.
 */

it('gives each special link its own icon, whatever the label says', function (string $key, string $icon): void {
    expect(MenuIconGuess::for('dynamic', $key, 'Tin tức'))->toBe($icon);
})->with([
    'home' => ['home', 'home'], 'search' => ['search', 'search'], 'cart' => ['cart', 'cart'],
    'account' => ['account', 'user'], 'login' => ['login', 'user'], 'call' => ['call', 'phone'],
    'zalo' => ['zalo', 'zalo'], 'mail' => ['mail', 'email'], 'map' => ['map', 'map'], 'sitemap' => ['sitemap', 'sitemap'],
]);

it('goes on to the label for a group heading, another kind of link and a special link of an unknown kind', function (): void {
    expect(MenuIconGuess::for('dynamic', 'group', 'Tin tức'))->toBe('newspaper')
        ->and(MenuIconGuess::for('url', null, 'Tin tức'))->toBe('newspaper')
        ->and(MenuIconGuess::for('entry', null, 'Giới thiệu'))->toBe('info')
        ->and(MenuIconGuess::for('dynamic', 'no-such-kind', 'Giới thiệu'))->toBe('info');
});

it('reads Vietnamese labels with accents and case ignored, never taking "Đối tác" for "đổi trả"', function (): void {
    expect(MenuIconGuess::for('url', null, 'Liên hệ'))->toBe('email')
        ->and(MenuIconGuess::for('url', null, 'LIEN HE'))->toBe('email')
        ->and(MenuIconGuess::for('url', null, 'Đối tác'))->toBe('handshake')
        ->and(MenuIconGuess::for('url', null, 'Đổi trả'))->toBe('refund')
        ->and(MenuIconGuess::for('url', null, 'Giỏ hàng của bạn'))->toBe('cart');
});

it('reads labels written in the other languages of the site', function (): void {
    expect(MenuIconGuess::for('url', null, 'Contact'))->toBe('email')
        ->and(MenuIconGuess::for('url', null, 'News'))->toBe('newspaper')
        ->and(MenuIconGuess::for('url', null, 'About Us'))->toBe('info')
        // one keyword inside a longer label proves nothing
        ->and(MenuIconGuess::for('url', null, 'Contact us'))->toBeNull();
});

it('finds Japanese and Chinese words anywhere in an unspaced label, the longest one winning', function (): void {
    expect(MenuIconGuess::for('url', null, '会社概要'))->toBe('info')              // 会社概要 (4 characters) over 会社 (2, building)
        ->and(MenuIconGuess::for('url', null, '会社'))->toBe('building')
        ->and(MenuIconGuess::for('url', null, 'お客様の声ページ'))->toBe('testimonial')
        ->and(MenuIconGuess::for('url', null, '关于我们'))->toBe('info')
        ->and(MenuIconGuess::for('url', null, '客户服务'))->toBe('support');
});

it('gives no icon when nothing matches, and ignores a lone word of two letters', function (): void {
    expect(MenuIconGuess::for('url', null, 'Xyzzy'))->toBeNull()
        ->and(MenuIconGuess::for('url', null, ''))->toBeNull()
        ->and(MenuIconGuess::for('url', null, 'us'))->toBeNull()
        ->and(MenuIconGuess::for('url', null, 'x'))->toBeNull();
});

it('prefers a longer run of words, then more letters, then the first icon in picker order', function (): void {
    // "gọi điện thoại" is a three-word run in phone's list; "gọi" alone would be a lone word.
    expect(MenuIconGuess::for('url', null, 'Gọi điện thoại'))->toBe('phone')
        // two icons hold "liên hệ" as a two-word run (email, send): the first in picker order wins
        ->and(MenuIconGuess::for('url', null, 'Liên hệ'))->toBe('email');
});

it('only ever names an icon of the website set', function (): void {
    foreach (['Trang chủ', 'Tin tức', 'Sản phẩm', 'Tuyển dụng', 'Hỏi đáp', 'Dịch vụ', 'Contact', '会社概要'] as $label) {
        $icon = MenuIconGuess::for('url', null, $label);
        expect($icon === null || StorefrontIcons::has($icon))->toBeTrue($label);
    }
});

it('gives no automatic icon to the labels where one word of a longer label would pick a wrong one, and keeps the right ones (the same cases as tests/js/admin-menus.test.mjs)', function (): void {
    $cases = json_decode((string) file_get_contents(base_path('tests/fixtures/menu-auto-icon-cases.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($cases['none'] as $label) {
        expect(MenuIconGuess::for('url', null, $label))->toBeNull($label);
    }
    foreach ($cases['icon'] as $label => $icon) {
        expect(MenuIconGuess::for('url', null, (string) $label))->toBe($icon, (string) $label);
    }
});
