<?php

declare(strict_types=1);

use App\Modules\Shared\Support\Slugger;

it('makes a plain-letter path from a name in any writing system', function (string $input, string $expected): void {
    expect(Slugger::make($input))->toBe($expected);
})->with([
    'vietnamese' => ['Ảnh công trình Đà Nẵng', 'anh-cong-trinh-da-nang'],
    'simplified chinese' => ['产品图片 2024', 'chan-pin-tu-pian-2024'],
    'traditional chinese' => ['產品圖片', 'chan-pin-tu-pian'],
    'japanese' => ['製品の写真 カタログ', 'zhi-pinno-xie-zhen-katarogu'],
    'korean' => ['제품 사진', 'jepum-sajin'],
    'thai' => ['รูปภาพสินค้า', 'rupphaph-sinkha'],
    'hebrew' => ['תמונות', 'tmwnwt'],
    'russian' => ['Фотографии продукции', 'fotografii-produkcii'],
    'arabic' => ['صور المنتجات', 'swr-almntjat'],
    'hindi' => ['उत्पाद चित्र', 'utpada-citra'],
    'german' => ['Größe Straße', 'grosse-strasse'],
    'french' => ['Été à Noël', 'ete-a-noel'],
    'turkish' => ['Işık İstanbul', 'isik-istanbul'],
    'emoji around words' => ['🎉 Khuyến mãi 🎉', 'khuyen-mai'],
    'latin plus chinese' => ['Máy CNC 机床', 'may-cnc-ji-chuang'],
    'symbols' => ['C++ & C#', 'c-c'],
    'khmer has no reading' => ['រូបភាពផលិតផល', ''],
    'lao has no reading' => ['ຮູບພາບ', ''],
    'emoji only' => ['🎉🎉', ''],
    'empty' => ['', ''],
]);

it('leaves a slug that is already [a-z0-9-] unchanged', function (string $slug): void {
    expect(Slugger::make($slug))->toBe($slug);
})->with(['gioi-thieu', 'tin-tuc-2024', 'a', 'san-pham-moi-nhat-3']);

it('cuts a long slug at a hyphen with no trailing hyphen', function (): void {
    expect(Slugger::make('alpha beta gamma delta', null, 12))->toBe('alpha-beta')
        ->and(Slugger::make('alpha beta gamma delta', null, 11))->toBe('alpha-beta')
        ->and(Slugger::make('alpha beta gamma delta', null, 10))->toBe('alpha-beta')
        ->and(Slugger::make('alpha beta gamma delta', null, 9))->toBe('alpha')
        ->and(Slugger::make('abcdefghij', null, 4))->toBe('abcd')
        ->and(strlen(Slugger::make(str_repeat('word ', 80), null, 200)))->toBeLessThanOrEqual(200);
});

it('says when the person must type the path', function (string $text, ?string $locale, bool $expected): void {
    expect(Slugger::needsManualInput($text, $locale))->toBe($expected);
})->with([
    'khmer' => ['រូបភាពផលិតផល', null, true],
    'lao' => ['ຮູບພາບ', 'lo', true],
    'japanese with kanji' => ['製品の写真', 'ja', true],
    'japanese kana only' => ['カタログ', 'ja', false],
    'chinese in zh' => ['产品图片', 'zh', false],
    'vietnamese' => ['Ảnh công trình', 'vi', false],
    'emoji only has no letters' => ['🎉', null, false],
    'blank' => ['   ', null, false],
    'kanji without ja locale' => ['製品', null, false],
    'kanji with kana and no locale' => ['製品カタログ', null, true],
    'chinese with no locale' => ['产品图片', null, false],
]);

it('returns no link for Japanese kanji, which ICU would read as Chinese', function (): void {
    $never = fn (): array => throw new RuntimeException('not asked');

    expect(Slugger::fromTitle('会社案内', 'ja', $never))->toBe('')
        ->and(Slugger::fromTitle('产品图片', 'zh', fn (): array => []))->toBe('chan-pin-tu-pian');
});

it('picks the lowest free number from one answer', function (): void {
    $calls = 0;
    $taken = function (string $base) use (&$calls): array {
        $calls++;

        return ['may', 'may-2', 'may-3', 'may-5'];
    };

    expect(Slugger::free('may', $taken))->toBe('may-4')
        ->and(Slugger::free('khac', fn (): array => ['may']))->toBe('khac')
        ->and($calls)->toBe(1);
});
