<?php

use App\Modules\Theme\Chrome\ContactIcons;
use App\Modules\Theme\Chrome\FloatingContact;
use App\Modules\Theme\Theming\ReadableColor;

/*
 * The floating-contact setting → what the theme prints. Pure: every value is checked for its
 * channel type and rebuilt into a link, colours are darkened until white marks read on them, and
 * anything unknown falls back to a safe default or is dropped.
 */

it('checks and tidies each value type', function (string $type, string $value, ?string $clean): void {
    expect(FloatingContact::clean($type, $value))->toBe($clean);
})->with([
    ['phone', '0912.345.678', '0912345678'],
    ['phone', '(+84) 912 345 678', '+84912345678'],
    ['phone', '091234567', null],
    ['phone', '+8491234567', null],
    ['zalo', 'zalo.me/0912345678', '0912345678'],
    ['zalo', 'https://www.zalo.me/0912345678/', '0912345678'],
    ['zalo', 'https://zalo.me.evil.test/0912345678', null],
    ['messenger', 'https://www.facebook.com/trang.mau/', 'trang.mau'],
    ['messenger', 'm.me/trang"mau', null],
    ['email', 'lienhe@mau.test', 'lienhe@mau.test'],
    ['email', 'lienhe@', null],
    ['telegram', 't.me/mau_shop', 'mau_shop'],
    ['telegram', '@1abcde', null],
    ['url', 'https://youtube.com/@mau', 'https://youtube.com/@mau'],
    ['url', '/lien-he', null],
    ['url', 'javascript:alert(1)', null],
    ['custom', '/lien-he', '/lien-he'],
    ['custom', 'MAILTO:lienhe@mau.test', 'mailto:lienhe@mau.test'],
    ['custom', 'sms:0912 345 678', 'sms:0912345678'],
    ['custom', 'data:text/html,x', null],
    ['nope', '0912345678', null],
]);

it('rejects a value longer than the cap', function (): void {
    expect(FloatingContact::clean('custom', 'https://example.com/'.str_repeat('a', FloatingContact::MAX_VALUE)))->toBeNull();
});

it('knows the type of a built-in channel and of the site’s own, nothing else', function (): void {
    expect(FloatingContact::typeOf('whatsapp'))->toBe('whatsapp')
        ->and(FloatingContact::typeOf('tiktok'))->toBe('url')
        ->and(FloatingContact::typeOf('c12'))->toBe('custom')
        ->and(FloatingContact::typeOf('c0'))->toBeNull()
        ->and(FloatingContact::typeOf('skype'))->toBeNull();
});

it('darkens a fill until white marks read on it: 3:1 for a glyph, 4.5:1 for a word', function (): void {
    $out = FloatingContact::for(['enabled' => true, 'channels' => [
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
        ['key' => 'c1', 'on' => true, 'value' => '/lien-he', 'color' => '#FFFF00', 'icon' => 'ri-chat-3-line'],
    ]], 'vi');

    [$phone, $zalo, $own] = $out['buttons'];
    expect(ReadableColor::ratio($phone['fill'], '#ffffff'))->toBeGreaterThanOrEqual(3.0)
        ->and(ReadableColor::ratio($zalo['fill'], '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and($zalo['text'])->toBe('Zalo')
        ->and(ReadableColor::ratio($own['fill'], '#ffffff'))->toBeGreaterThanOrEqual(3.0)
        ->and($own['icon'])->toBe(ContactIcons::path('ri-chat-3-line'))
        ->and($phone['pulse'])->toBeTrue();
});

it('drops a repeated or unknown channel and caps the list', function (): void {
    $channels = [['key' => 'phone', 'on' => true, 'value' => '0912345678'], ['key' => 'skype', 'on' => true, 'value' => 'x']];
    for ($n = 1; $n <= 30; $n++) {
        $channels[] = ['key' => 'c'.$n, 'on' => true, 'value' => '/p'.$n];
    }
    $out = FloatingContact::for(['enabled' => true, 'channels' => $channels], 'vi');

    expect(count($out['buttons']))->toBe(FloatingContact::MAX_CHANNELS - 1)
        ->and(array_column($out['buttons'], 'key'))->not->toContain('skype');
});

it('picks the hover label of the page’s language, else the name of a channel of the site’s own', function (): void {
    $out = FloatingContact::for(['enabled' => true, 'channels' => [
        ['key' => 'phone', 'on' => true, 'value' => '0912345678', 'tips' => ['vi' => 'Gọi ngay', 'en' => 'Call now']],
        ['key' => 'c1', 'on' => true, 'value' => '/lien-he', 'name' => 'Đặt lịch', 'tips' => ['en' => 'Book']],
    ]], 'vi');

    expect(array_column($out['buttons'], 'label'))->toBe(['Gọi ngay', 'Đặt lịch'])
        ->and(array_column(FloatingContact::for(['enabled' => true, 'channels' => [
            ['key' => 'email', 'on' => true, 'value' => 'a@b.test', 'tips' => ['vi' => 'Gửi thư']],
        ]], 'ja')['buttons'], 'label'))->toBe([null]);
});
