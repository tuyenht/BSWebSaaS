<?php

use Illuminate\Support\Facades\View;

/*
 * A stats block saved without `kind` / `variant` and a cta block saved without `button2_*`, `side` or
 * `extra_style` draw the exact bytes they drew before those props existed. The golden files hold the
 * output of the templates as they were before.
 */

/** @return array<string, array{0: string, 1: array<string, mixed>}> */
function statsCtaOldCases(): array
{
    return [
        'stats' => ['stats', ['props' => ['heading' => 'Số liệu', 'items' => [
            ['value' => 2000, 'suffix' => '+', 'label' => 'Công trình'],
            ['value' => '15', 'label' => 'Năm kinh nghiệm'],
            ['label' => 'Chỉ chữ'],
            ['value' => 98, 'suffix' => '%', 'label' => 'Hài lòng'],
        ]]]],
        'stats dark band' => ['stats', ['props' => ['bg' => 'dark', 'items' => [['value' => 3, 'label' => 'Nhà máy'], ['value' => 4300, 'suffix' => ' m²', 'label' => 'Nhà xưởng']]]]],
        'cta' => ['cta', ['props' => ['heading' => 'Cần báo giá?', 'text' => 'Gửi bản vẽ.', 'button_label' => 'Liên hệ', 'button_url' => '/lien-he']]],
        'cta rounded' => ['cta', ['props' => ['heading' => 'Cần báo giá?', 'text' => 'Gửi bản vẽ.', 'button_label' => 'Liên hệ', 'button_url' => '/lien-he', 'variant' => 'rounded']]],
        'cta without button' => ['cta', ['props' => ['heading' => 'Chỉ tiêu đề']]],
    ];
}

it('draws a stats or cta block saved before the new props as the exact bytes it always drew', function (string $name): void {
    app()->setLocale('vi');
    [$view, $data] = statsCtaOldCases()[$name];
    $file = __DIR__.'/golden/old-block-'.str_replace(' ', '-', $name).'.html';

    expect(View::make('theme::sections.'.$view, $data)->render())->toBe(file_get_contents($file));
})->with(array_keys(statsCtaOldCases()));
