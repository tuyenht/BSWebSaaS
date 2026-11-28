<?php

use App\Modules\Shared\Support\Bytes;

/*
 * One formatter for sizes across the admin: the number in the page language's format (Vietnamese
 * «1,5 MB», English «1.5 MB»), the unit from the language files.
 */

it('writes a file size the way the media boards do, in the locale\'s number format', function (int $bytes, string $locale, string $want): void {
    expect(Bytes::size($bytes, $locale))->toBe($want);
})->with([
    'a few bytes read as 1 KB' => [10, 'vi', '1 KB'],
    'nothing is still 1 KB' => [0, 'vi', '1 KB'],
    'kilobytes, rounded' => [880_640, 'vi', '860 KB'],
    'a thousand kilobytes, Vietnamese grouping' => [1_023 * 1024, 'vi', '1.023 KB'],
    'one mebibyte' => [1_048_576, 'vi', '1,0 MB'],
    'one and a half, Vietnamese' => [1_572_864, 'vi', '1,5 MB'],
    'one and a half, English' => [1_572_864, 'en', '1.5 MB'],
    'one and a half, Japanese' => [1_572_864, 'ja', '1.5 MB'],
    'one and a half, Chinese' => [1_572_864, 'zh', '1.5 MB'],
]);

it('follows the page language when no locale is given', function (): void {
    app()->setLocale('vi');
    expect(Bytes::size(1_572_864))->toBe('1,5 MB');

    app()->setLocale('en');
    expect(Bytes::size(1_572_864))->toBe('1.5 MB');
});

it('writes a total in megabytes with no «,0», to the places asked', function (): void {
    expect(Bytes::megabytes((int) round(312.4 * 1048576), locale: 'vi'))->toBe('312,4 MB')
        ->and(Bytes::megabytes(500 * 1048576, 0, 'vi'))->toBe('500 MB')
        ->and(Bytes::megabytes(500 * 1048576, 1, 'vi'))->toBe('500 MB')
        ->and(Bytes::megabytes(10_240 * 1024, 0, 'vi'))->toBe('10 MB')
        ->and(Bytes::megabytes((int) round(312.4 * 1048576), locale: 'en'))->toBe('312.4 MB');
});

it('writes a total in gigabytes as the storage card does: megabytes over a thousand, one place at most', function (): void {
    expect(Bytes::gigabytes(1200 * 1048576, 'vi'))->toBe('1,2 GB')
        ->and(Bytes::gigabytes(2000 * 1048576, 'vi'))->toBe('2 GB')
        ->and(Bytes::gigabytes(1200 * 1048576, 'en'))->toBe('1.2 GB');
});

it('takes its unit words from the language files', function (): void {
    foreach (['vi', 'en', 'ja', 'zh'] as $locale) {
        $lang = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        expect($lang)->toHaveKeys(['bytes.kb', 'bytes.mb', 'bytes.gb']);
    }
});
