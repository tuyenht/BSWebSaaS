<?php

use Illuminate\Support\Facades\View;

/*
 * An about, contact or entry-grid block saved before the letter look, the links, the image focus, the
 * Zalo cell and the zigzag look draws the exact bytes it always drew. The golden files hold the output
 * of the templates as they were before those props existed. (Entry grids with the other looks are
 * locked by ListDisplayOldBlocksTest.)
 */

/** @return array<string, array{0: string, 1: array<string, mixed>}> */
function aboutContactOldCases(): array
{
    $full = ['heading' => 'Giới thiệu Ấ', 'paragraphs' => ['Chào Quý vị Ế', 'Đoạn hai', 'Đoạn ba', 'Đoạn bốn Ợ'], 'image_url' => 'https://f/a.webp', 'cta_label' => 'Xem thêm', 'cta_url' => '/gioi-thieu', '_high' => true];

    return [
        'about' => ['about', ['props' => $full]],
        'about minimal' => ['about', ['props' => ['paragraphs' => ['Một đoạn']]]],
        'contact list' => ['contact', ['props' => ['heading' => 'Liên hệ Ế', 'address' => 'Số 1 Đường Ợ', 'phone' => '0915668383', 'email' => 'a@b.vn', 'map_embed' => '']]],
        'contact cards' => ['contact', ['props' => ['variant' => 'cards', 'heading' => 'Liên hệ Ế', 'address' => 'Số 1 Đường Ợ', 'phone' => '0915668383', 'email' => 'a@b.vn', 'phone_label' => 'Gọi Ấ']]],
    ];
}

it('draws an about or contact block saved before the new props as the exact bytes it always drew', function (string $name): void {
    app()->setLocale('vi');
    [$view, $data] = aboutContactOldCases()[$name];
    $file = __DIR__.'/golden/old-block-'.str_replace(' ', '-', $name).'.html';

    expect(View::make('theme::sections.'.$view, $data)->render())->toBe(file_get_contents($file));
})->with(array_keys(aboutContactOldCases()));
