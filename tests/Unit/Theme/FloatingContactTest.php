<?php

use App\Modules\Admin\Support\Settings\FloatingContactInput;
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
    'mobile +84 with the 0 kept' => ['phone', '+84 0912 345 678', '+84912345678'],
    'mobile 0084' => ['phone', '0084 912 345 678', '+84912345678'],
    'mobile with no-break spaces' => ['phone', "0912\u{00A0}345\u{00A0}678", '0912345678'],
    'mobile +84 too short refused' => ['phone', '+84 1234 5678', null],
    'zalo mobile +84 (0)' => ['zalo', '+84 (0)912 345 678', '+84912345678'],
    'zalo mobile with 11 digits refused' => ['zalo', '0912 345 6789', null],
    'whatsapp +84 must be Vietnamese' => ['whatsapp', '+84 1234 5678', null],
    'viber country code 0 refused' => ['viber', '+0123456789', null],
    'whatsapp +84 mobile' => ['whatsapp', '+84 912 345 678', '+84912345678'],
    'whatsapp abroad (0)' => ['whatsapp', '+44 (0)20 7946 0018', '+442079460018'],
    'hotline 1900 + 4' => ['phone', '1900 1234', '19001234'],
    'hotline 1800 + 6' => ['phone', '1800-123-456', '1800123456'],
    'hotline 1900 + 5' => ['phone', '1900 12345', null],
    'hotline other prefix' => ['phone', '1700 1234', null],
    'sms hotline' => ['sms', '1900.1234', '19001234'],
    'call abroad' => ['phone', '+65 9123 4567', '+6591234567'],
    'sms abroad' => ['sms', '+65 9123 4567', '+6591234567'],
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

it('puts every finished channel in the bar in the admin\'s order, chat channels included, with no cap of five', function (): void {
    $keys = ['phone', 'messenger', 'zalo', 'email', 'sms', 'whatsapp', 'viber', 'telegram'];
    $raw = ['enabled' => true, 'theme' => 'horizontal', 'buy' => ['enabled' => true, 'color' => '#0ab39c'], 'channels' => array_map(
        static fn (string $key): array => ['key' => $key, 'on' => true, 'value' => match ($key) {
            'email' => 'a@mau.test',
            'messenger' => 'trang.mau',
            'telegram' => 'trangmau',
            default => '0912345678',
        }],
        $keys,
    )];

    expect(array_column(FloatingContact::for($raw, 'vi', '/san-pham')['bar'], 'key'))->toBe($keys)
        ->and(array_column(FloatingContact::for($raw, 'vi')['bar'], 'key'))->toBe($keys)
        ->and(FloatingContact::for($raw, 'vi')['phone_bar'])->toBeNull();
});

it('keeps the board phone bar of a round-button site without the chat channels and at three cells', function (): void {
    $raw = ['enabled' => true, 'phone_bar' => ['enabled' => true], 'channels' => array_map(
        static fn (string $key): array => ['key' => $key, 'on' => true, 'value' => $key === 'email' ? 'a@mau.test' : '0912345678'],
        ['messenger', 'email', 'phone', 'zalo', 'sms', 'viber'],
    )];

    expect(array_column(FloatingContact::for($raw, 'vi')['phone_bar']['bar'], 'key'))->toBe(['phone', 'zalo', 'sms']);
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
        ->and(array_column($with['bar'], 'key'))->toBe(['phone', 'zalo', 'sms']);

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

it('keeps a light fill the site chose with a dark ink on the phone bar only; round buttons and the bar theme keep the darkened fill with white', function (): void {
    $channels = [
        ['key' => 'phone', 'on' => true, 'value' => '0912345678', 'color' => '#f59e0b'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678', 'color' => '#0068ff'],
    ];
    $out = FloatingContact::for(['enabled' => true, 'channels' => $channels, 'phone_bar' => ['enabled' => true]], 'vi');

    [$phone, $zalo] = $out['phone_bar']['bar'];
    expect($phone['fill'])->toBe('#f59e0b')
        ->and($phone['ink'])->toBe(FloatingContact::DARK_INK)
        ->and(ReadableColor::ratio($phone['fill'], $phone['ink']))->toBeGreaterThanOrEqual(3.0)
        ->and($zalo['fill'])->toBe('#0068ff')
        ->and($zalo['ink'])->toBe('#ffffff');

    // Round buttons: the old rule, the colour darkened until white reads on it.
    foreach ([$out['buttons'], FloatingContact::for(['enabled' => true, 'theme' => 'horizontal', 'channels' => $channels], 'vi')['bar']] as $set) {
        expect($set[0]['ink'])->toBe('#ffffff')
            ->and($set[0]['fill'])->not->toBe('#f59e0b')
            ->and(ReadableColor::ratio($set[0]['fill'], '#ffffff'))->toBeGreaterThanOrEqual(3.0);
    }
});

it('builds the board phone bar: the call first, then the other quick channels, then the site\'s own cell, three cells at most', function (): void {
    $raw = ['enabled' => true, 'theme' => 'vertical', 'channels' => [
        ['key' => 'messenger', 'on' => true, 'value' => 'page'],
        ['key' => 'zalo', 'on' => true, 'value' => '0912345678', 'tips' => ['vi' => 'Chat Zalo']],
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'sms', 'on' => true, 'value' => '0912345678'],
    ], 'phone_bar' => ['enabled' => true, 'cta' => ['label' => ['vi' => 'Yêu cầu báo giá'], 'url' => '/lien-he']]];

    $bar = FloatingContact::for($raw, 'vi')['phone_bar'];
    expect(array_column($bar['bar'], 'key'))->toBe(['phone', 'zalo'])
        ->and($bar['cta'])->toBe(['href' => '/lien-he', 'label' => 'Yêu cầu báo giá']);

    // another language falls back to the label that was given; no cell when the link is unsafe
    expect(FloatingContact::for($raw, 'en')['phone_bar']['cta']['label'])->toBe('Yêu cầu báo giá');
    $raw['phone_bar']['cta']['url'] = 'javascript:alert(1)';
    $noCta = FloatingContact::for($raw, 'vi')['phone_bar'];
    expect($noCta['cta'])->toBeNull()
        ->and(array_column($noCta['bar'], 'key'))->toBe(['phone', 'zalo', 'sms']);
});

it('has no phone bar unless switched on, and never on the bar theme', function (): void {
    $channels = [['key' => 'phone', 'on' => true, 'value' => '0912345678']];

    expect(FloatingContact::for(['enabled' => true, 'channels' => $channels], 'vi')['phone_bar'])->toBeNull()
        ->and(FloatingContact::for(['enabled' => true, 'channels' => $channels, 'phone_bar' => ['enabled' => false]], 'vi')['phone_bar'])->toBeNull()
        ->and(FloatingContact::for(['enabled' => true, 'theme' => 'horizontal', 'channels' => $channels, 'phone_bar' => ['enabled' => true]], 'vi')['phone_bar'])->toBeNull();
});

it('marks a channel switched on but not filled in as an unfinished slot in the admin preview only', function (): void {
    $raw = ['enabled' => true, 'theme' => 'horizontal', 'channels' => [
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'messenger', 'on' => true, 'value' => ''],
        ['key' => 'zalo', 'on' => true, 'value' => ''],
        ['key' => 'sms', 'on' => true, 'value' => 'not a number'],
        ['key' => 'email', 'on' => false, 'value' => ''],
        ['key' => 'whatsapp', 'on' => true, 'value' => ''],
        ['key' => 'telegram', 'on' => true, 'value' => ''],
        ['key' => 'viber', 'on' => true, 'value' => ''],
    ]];
    $pending = static fn (array $list): array => array_column(array_filter($list, static fn (array $b): bool => $b['pending'] ?? false), 'key');

    // The site: only what passes, never a slot.
    $site = FloatingContact::for($raw, 'vi');
    expect(array_column($site['buttons'], 'key'))->toBe(['phone'])
        ->and($pending($site['buttons']))->toBe([])
        ->and($site['buttons'][0])->not->toHaveKey('pending');

    // The preview: every switched-on channel at its place, the unfinished ones marked; the bar
    // holds all of them.
    $preview = FloatingContact::preview($raw, 'vi');
    expect(array_column($preview['buttons'], 'key'))->toBe(['phone', 'messenger', 'zalo', 'sms', 'whatsapp', 'telegram', 'viber'])
        ->and($pending($preview['buttons']))->toBe(['messenger', 'zalo', 'sms', 'whatsapp', 'telegram', 'viber'])
        ->and(array_column($preview['bar'], 'key'))->toBe(['phone', 'messenger', 'zalo', 'sms', 'whatsapp', 'telegram', 'viber'])
        ->and(collect($preview['bar'])->firstWhere('key', 'zalo')['href'])->toBe('');

    // Nothing filled in yet: the site shows nothing, the preview still shows where the buttons go.
    $empty = ['enabled' => true, 'theme' => 'vertical', 'channels' => [['key' => 'sms', 'on' => true, 'value' => '']]];
    expect(FloatingContact::for($empty, 'vi'))->toBeNull()
        ->and(array_column(FloatingContact::preview($empty, 'vi')['buttons'], 'key'))->toBe(['sms']);
});

it('keeps every slot at its place in the bar preview and the finished cells exactly the site\'s bar', function (array $channels, ?string $shop, array $bar): void {
    $raw = ['enabled' => true, 'theme' => 'horizontal', 'buy' => ['enabled' => true, 'color' => '#0ab39c'], 'channels' => $channels];

    expect(array_column(FloatingContact::preview($raw, 'vi', $shop)['bar'], 'key'))->toBe($bar)
        ->and(array_column(array_values(array_filter(FloatingContact::preview($raw, 'vi', $shop)['bar'], fn (array $b): bool => ! isset($b['pending']))), 'key'))
        ->toBe(array_column(FloatingContact::for($raw, 'vi', $shop)['bar'], 'key'));
})->with([
    'five finished + one slot' => [[
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'zalo', 'on' => true, 'value' => ''],
        ['key' => 'sms', 'on' => true, 'value' => '0912345678'],
        ['key' => 'whatsapp', 'on' => true, 'value' => '0912345678'],
        ['key' => 'telegram', 'on' => true, 'value' => 'trangmau'],
        ['key' => 'viber', 'on' => true, 'value' => '0912345678'],
    ], null, ['phone', 'zalo', 'sms', 'whatsapp', 'telegram', 'viber']],
    'a slot in the middle keeps the setting order' => [[
        ['key' => 'phone', 'on' => true, 'value' => '0912345678'],
        ['key' => 'messenger', 'on' => true, 'value' => ''],
        ['key' => 'zalo', 'on' => true, 'value' => ''],
        ['key' => 'sms', 'on' => true, 'value' => '0912345678'],
    ], null, ['phone', 'messenger', 'zalo', 'sms']],
]);

it('keeps every number valid before cleaning to the same string, and refuses what was refused', function (string $type, string $value, ?string $clean): void {
    expect(FloatingContact::clean($type, $value))->toBe($clean);
})->with([
    'mobile spaced' => ['phone', '0912 345 678', '0912345678'],
    'mobile +84' => ['phone', '+84 912 345 678', '+84912345678'],
    'hotline 1900' => ['phone', '1900 1234', '19001234'],
    'hotline 1800' => ['phone', '1800 123456', '1800123456'],
    'landline' => ['phone', '024 3123 4567', '02431234567'],
    'sms mobile' => ['sms', '0912 345 678', '0912345678'],
    'zalo stays Vietnamese mobile' => ['zalo', '+81 3-1234-5678', null],
    'javascript' => ['phone', 'javascript:alert(1)', null],
    'letters' => ['phone', 'abc', null],
    'country code 0' => ['phone', '+0 123', null],
    'too short' => ['phone', '12345', null],
    'sms javascript' => ['sms', 'javascript:alert(1)', null],
    'sms country code 0' => ['sms', '+0 123', null],
]);

it('accepts a number of another country for the call and SMS buttons and links it with tel: and sms:', function (string $value, string $clean): void {
    expect(FloatingContact::clean('phone', $value))->toBe($clean)
        ->and(FloatingContact::href('phone', $clean))->toBe('tel:'.$clean)
        ->and(FloatingContact::clean('sms', $value))->toBe($clean)
        ->and(FloatingContact::href('sms', $clean))->toBe('sms:'.$clean);
})->with([
    'Japan' => ['+81 3-1234-5678', '+81312345678'],
    'United Kingdom' => ['+44 20 7946 0958', '+442079460958'],
    'United States' => ['+1 (415) 555-0100', '+14155550100'],
]);

it('reads the layout of a stored setting, from its own field or from the theme and phone bar of an older save', function (mixed $raw, string $layout): void {
    expect(FloatingContact::layout($raw))->toBe($layout);
})->with([
    'never saved' => [null, 'combo'],
    'not an array' => ['x', 'combo'],
    'own field' => [['layout' => 'horizontal', 'theme' => 'vertical'], 'horizontal'],
    'own field wins over a phone bar' => [['layout' => 'vertical', 'phone_bar' => ['enabled' => true]], 'vertical'],
    'bad own field falls back to the theme' => [['layout' => 'grid', 'theme' => 'horizontal'], 'combo'],
    'older horizontal' => [['theme' => 'horizontal'], 'combo'],
    'older vertical with the phone bar on' => [['theme' => 'vertical', 'phone_bar' => ['enabled' => true]], 'combo'],
    'older vertical with the phone bar off' => [['theme' => 'vertical', 'phone_bar' => ['enabled' => false]], 'vertical'],
    'older vertical alone' => [['theme' => 'vertical'], 'vertical'],
    'theme missing, phone bar on' => [['phone_bar' => ['enabled' => true]], 'combo'],
    'theme invalid, no phone bar' => [['theme' => 'diagonal'], 'vertical'],
    'empty array' => [[], 'vertical'],
]);

it('gives older code the theme word a layout stands for', function (): void {
    expect(FloatingContact::legacyTheme('combo'))->toBe('horizontal')
        ->and(FloatingContact::legacyTheme('vertical'))->toBe('vertical')
        ->and(FloatingContact::legacyTheme('horizontal'))->toBe('horizontal');
});

it('reads the fold, bar tone and click counting with their defaults', function (): void {
    expect(FloatingContact::dial(null))->toBeTrue()
        ->and(FloatingContact::dial([]))->toBeTrue()
        ->and(FloatingContact::dial(['dial' => 0]))->toBeTrue()
        ->and(FloatingContact::dial(['dial' => false]))->toBeFalse()
        ->and(FloatingContact::track(null))->toBeTrue()
        ->and(FloatingContact::track(['track' => 'no']))->toBeTrue()
        ->and(FloatingContact::track(['track' => false]))->toBeFalse()
        ->and(FloatingContact::barTone(null))->toBe('auto')
        ->and(FloatingContact::barTone(['bar_tone' => 'dark']))->toBe('dark')
        ->and(FloatingContact::barTone(['bar_tone' => 'light']))->toBe('light')
        ->and(FloatingContact::barTone(['bar_tone' => 'purple']))->toBe('auto');
});

it('chooses the bottom bar cells, the sheet and the "Thêm" cell', function (array $ready, bool $buy, array $bar, array $sheet, bool $more): void {
    expect(FloatingContact::barSlots($ready, $buy))->toBe(['bar' => $bar, 'sheet' => $sheet, 'more' => $more]);
})->with([
    'nothing ready' => [[], false, [], [], false],
    'three channels, e-mail first, no shop' => [['email', 'zalo', 'phone'], false, ['email', 'zalo', 'phone'], [], false],
    'three channels, e-mail first, with shop' => [['email', 'zalo', 'phone'], true, ['email', 'zalo', 'phone'], [], false],
    'two sheet-only channels left over' => [['phone', 'zalo', 'messenger', 'email'], false, ['phone', 'zalo'], ['messenger', 'email'], true],
    'six quick channels with shop' => [['phone', 'zalo', 'sms', 'whatsapp', 'viber', 'telegram'], true, ['phone', 'zalo', 'sms'], ['whatsapp', 'viber', 'telegram'], true],
    'five quick channels and e-mail, bar full' => [['phone', 'zalo', 'sms', 'whatsapp', 'viber', 'email'], false, ['phone', 'zalo', 'sms', 'whatsapp'], ['viber', 'email'], true],
    'four quick channels with shop' => [['phone', 'zalo', 'sms', 'whatsapp'], true, ['phone', 'zalo', 'sms', 'whatsapp'], [], false],
    'five quick channels, no shop' => [['phone', 'zalo', 'sms', 'whatsapp', 'viber'], false, ['phone', 'zalo', 'sms', 'whatsapp', 'viber'], [], false],
]);

it('folds the round buttons of a phone into one button only on the vertical layout with the switch on and enough channels', function (): void {
    expect(FloatingContact::dials('vertical', true, FloatingContact::DIAL_FROM))->toBeTrue()
        ->and(FloatingContact::dials('vertical', true, FloatingContact::DIAL_FROM - 1))->toBeFalse()
        ->and(FloatingContact::dials('vertical', false, 9))->toBeFalse()
        ->and(FloatingContact::dials('combo', true, 9))->toBeFalse()
        ->and(FloatingContact::dials('horizontal', true, 9))->toBeFalse();
});

it('checks the newer fields of a setting written from outside the form, with a plain message for a wrong one', function (array $value, array $errors): void {
    expect(FloatingContactInput::errors($value + ['channels' => []], ['vi']))->toBe($errors);
})->with([
    'all valid' => [['layout' => 'horizontal', 'dial' => false, 'bar_tone' => 'dark', 'track' => true], []],
    'older theme only' => [['theme' => 'horizontal'], []],
    'layout and theme together' => [['layout' => 'vertical', 'theme' => 'horizontal'], []],
    'unknown layout' => [['layout' => 'grid'], ["'layout' must be one of combo, vertical, horizontal."]],
    'unknown tone' => [['bar_tone' => 'purple'], ["'bar_tone' must be one of auto, light, dark."]],
    'dial not a bool' => [['dial' => 'yes'], ["'dial' must be true or false."]],
    'track not a bool' => [['track' => 1], ["'track' must be true or false."]],
]);

it('stores the newer fields after the existing ones, and writes the older theme word that matches a given layout', function (): void {
    $stored = FloatingContactInput::stored(['enabled' => true, 'theme' => 'vertical', 'layout' => 'horizontal', 'dial' => false, 'bar_tone' => 'light', 'track' => false], ['vi']);

    expect($stored)->toMatchArray(['theme' => 'horizontal', 'layout' => 'horizontal', 'dial' => false, 'bar_tone' => 'light', 'track' => false])
        ->and(array_slice(array_keys($stored), 0, 7))->toBe(['enabled', 'theme', 'position', 'offset_x', 'offset_y', 'layout', 'dial']);

    $older = FloatingContactInput::stored(['enabled' => true, 'theme' => 'horizontal'], ['vi']);
    expect($older)->toMatchArray(['theme' => 'horizontal', 'layout' => 'combo', 'dial' => true, 'bar_tone' => 'auto', 'track' => true])
        ->and(FloatingContactInput::stored([], ['vi'])['theme'])->toBe('vertical');
});
