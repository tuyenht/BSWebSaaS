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
    'landline' => ['phone', '024 1234 5678', '02412345678'],
    'landline +84' => ['phone', '+84 24 1234 5678', '+842412345678'],
    'landline too long' => ['phone', '024 1234 56789', null],
    'landline 4-digit area code' => ['phone', '0236 382 1234', '02363821234'],
    'landline +84 (0)' => ['phone', '+84 (0)24 3826 1234', '+842438261234'],
    'landline not starting 02' => ['phone', '0643 826 1234', null],
    'landline 10 digits refused' => ['phone', '024 3826 123', null],
    'mobile 03' => ['phone', '0352 345 678', '0352345678'],
    'mobile 05' => ['phone', '056 234 5678', '0562345678'],
    'mobile 07' => ['phone', '0701 234 567', '0701234567'],
    'mobile 08' => ['phone', '0812.345.678', '0812345678'],
    'mobile +84 (0)' => ['phone', '+84 (0)912 345 678', '+84912345678'],
    'mobile +84' => ['sms', '+84 912 345 678', '+84912345678'],
    'mobile prefix 01 refused' => ['phone', '0112 345 678', null],
    'mobile prefix 04 refused' => ['phone', '0412 345 678', null],
    'mobile with 11 digits refused' => ['phone', '0912 345 6789', null],
    'mobile +84 with the 0 kept refused' => ['phone', '+84 0912 345 678', null],
    'zalo mobile +84 (0)' => ['zalo', '+84 (0)912 345 678', '+84912345678'],
    'zalo mobile with 11 digits refused' => ['zalo', '0912 345 6789', null],
    'whatsapp +84 must be Vietnamese' => ['whatsapp', '+84 0912 345 678', null],
    'whatsapp +84 mobile' => ['whatsapp', '+84 912 345 678', '+84912345678'],
    'whatsapp abroad (0)' => ['whatsapp', '+44 (0)20 7946 0018', '+442079460018'],
    'hotline 1900 + 4' => ['phone', '1900 1234', '19001234'],
    'hotline 1800 + 6' => ['phone', '1800-123-456', '1800123456'],
    'hotline 1900 + 5' => ['phone', '1900 12345', null],
    'hotline other prefix' => ['phone', '1700 1234', null],
    'sms hotline' => ['sms', '1900.1234', '19001234'],
    'call abroad refused' => ['phone', '+65 9123 4567', null],
    'whatsapp abroad' => ['whatsapp', '+65 9123 4567', '+6591234567'],
    'viber abroad 15 digits' => ['viber', '+123456789012345', '+123456789012345'],
    'viber abroad too short' => ['viber', '+1234567', null],
    'viber abroad too long' => ['viber', '+1234567890123456', null],
    'whatsapp landline' => ['whatsapp', '024 1234 5678', '02412345678'],
    'whatsapp hotline refused' => ['whatsapp', '1900 1234', null],
    'zalo landline refused' => ['zalo', '024 1234 5678', null],
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
    'vbscript' => ['url', 'vbscript:msgbox(1)', null],
    'leading space javascript' => ['custom', '  javascript:alert(1)', null],
    'leading control char javascript' => ['custom', "\x01javascript:alert(1)", null],
    'tab inside javascript' => ['custom', "java\tscript:alert(1)", null],
    'percent-encoded javascript' => ['custom', '%6Aavascript:alert(1)', null],
    'percent-encoded javascript url' => ['url', '%6A%61%76%61script:alert(1)', null],
    'upper-case https kept' => ['url', 'HTTPS://Example.com/x', 'HTTPS://Example.com/x'],
    'upper-case https custom' => ['custom', 'HTTPS://example.com', 'HTTPS://example.com'],
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
    $channels = [['key' => 'phone', 'on' => true, 'value' => '0912345678'], ['key' => 'skype', 'on' => true, 'value' => 'x'],
        ['key' => 'phone', 'on' => true, 'value' => '0987654321']];
    for ($n = 1; $n <= 30; $n++) {
        $channels[] = ['key' => 'c'.$n, 'on' => true, 'value' => '/p'.$n];
    }
    $out = FloatingContact::for(['enabled' => true, 'channels' => $channels], 'vi');

    // 20 kept rows: one phone, the unknown and the repeated phone dropped, then c1–c17.
    expect(count($out['buttons']))->toBe(FloatingContact::MAX_CHANNELS - 2)
        ->and(array_column($out['buttons'], 'key'))->not->toContain('skype')
        ->and(array_count_values(array_column($out['buttons'], 'key'))['phone'])->toBe(1)
        ->and($out['buttons'][0]['href'])->toBe('tel:0912345678');
});

it('keeps the first of a repeated channel in the phone bar too', function (): void {
    $out = FloatingContact::for(['enabled' => true, 'theme' => 'horizontal', 'channels' => [
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
        ['key' => 'zalo', 'on' => true, 'value' => '0987654321'],
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
    ]], 'vi');

    expect(array_column($out['bar'], 'key'))->toBe(['zalo', 'phone'])
        ->and($out['bar'][0]['href'])->toBe('https://zalo.me/0912345678');
});

it('ends the phone bar with "Mua hàng" only when it is on and the site has a product list', function (): void {
    $raw = ['enabled' => true, 'theme' => 'horizontal', 'buy' => ['enabled' => true, 'color' => '#FFFFFF'], 'channels' => [
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678'],
        ['key' => 'sms', 'on' => true, 'value' => '0912345678'],
    ]];

    $with = FloatingContact::for($raw, 'vi', '/san-pham');
    expect($with['buy'])->toMatchArray(['href' => '/san-pham', 'ink' => '#ffffff', 'icon' => ContactIcons::path('ri-shopping-bag-fill')])
        ->and(ReadableColor::ratio($with['buy']['fill'], '#ffffff'))->toBeGreaterThanOrEqual(3.0)
        ->and(array_column($with['bar'], 'key'))->toBe(['phone', 'zalo']);

    // No product list: the switch is kept but nothing is drawn, and the bar keeps three channels.
    $without = FloatingContact::for($raw, 'vi');
    expect($without['buy'])->toBeNull()
        ->and(array_column($without['bar'], 'key'))->toBe(['phone', 'zalo', 'sms']);

    // The brand colour uses the site's brand fill and its readable ink; off or round buttons: none.
    expect(FloatingContact::for(['buy' => ['enabled' => true, 'color' => 'brand']] + $raw, 'vi', '/san-pham')['buy'])
        ->toMatchArray(['fill' => 'var(--color-primary)', 'ink' => 'var(--color-on-primary)'])
        ->and(FloatingContact::for(['buy' => ['enabled' => false]] + $raw, 'vi', '/san-pham')['buy'])->toBeNull()
        ->and(FloatingContact::for(['theme' => 'vertical'] + $raw, 'vi', '/san-pham')['buy'])->toBeNull()
        ->and(FloatingContact::for(['buy' => ['enabled' => true, 'color' => 'red;x:url(y)']] + $raw, 'vi', '/san-pham')['buy']['fill'])->toStartWith('#');
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
