<?php

use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Support\Facades\View;

/*
 * Theme section rendering: the about block (a static company-intro section), and the entry_grid
 * card's SMART featured image — a glyph key renders an inline SVG, a URL/path renders an <img>
 * (one image_url concept). Services + projects are data-bound posts shown via entry_grid; each
 * card links to the post's detail page.
 */

it('registers the about block with url-typed sinks', function () {
    $registry = app(ComponentRegistry::class);

    expect($registry->has('about'))->toBeTrue();
    $about = $registry->get('about')->schema();
    expect($about['image_url']['type'])->toBe('url');
    expect($about['cta_url']['type'])->toBe('url');
});

it('renders the about block with paragraphs, image and CTA', function () {
    $props = [
        'heading' => 'Về An Vinh',
        'paragraphs' => ['Đoạn một.', 'Đoạn hai.'],
        'image_url' => 'https://cdn.example/about.jpg',
        'cta_label' => 'Tìm hiểu thêm', 'cta_url' => '/gioi-thieu',
    ];

    $html = View::make('theme::sections.about', ['props' => $props])->render();

    expect($html)
        ->toContain('Về An Vinh')
        ->toContain('Đoạn một.')->toContain('Đoạn hai.')
        ->toContain('src="https://cdn.example/about.jpg"')
        ->toContain('href="/gioi-thieu">Tìm hiểu thêm');
});

it('renders the entry_grid card image smartly: glyph key → SVG, URL → <img>, card → detail link', function () {
    $view = ['props' => ['heading' => 'Dịch vụ'], 'items' => [
        ['image' => 'shield', 'title' => 'Cách nhiệt', 'url' => '/dich-vu/cach-nhiet', 'short_outcome' => '', 'sector' => ''],
        ['image' => 'https://cdn.example/forklift.jpg', 'title' => 'Xe nâng', 'url' => '/dich-vu/xe-nang', 'short_outcome' => '', 'sector' => ''],
    ]];

    $html = View::make('theme::sections.entry_grid', $view)->render();

    expect($html)
        ->toContain('Dịch vụ')
        ->toContain('<svg')                                       // glyph key → inline SVG (service icon)
        ->toContain('src="https://cdn.example/forklift.jpg"')     // URL → <img> (photo)
        ->toContain('href="/dich-vu/cach-nhiet">Cách nhiệt');     // card links to the service detail page
});

it('renders no icon for an unknown glyph key (no injection surface)', function () {
    $view = ['props' => [], 'items' => [
        ['image' => 'definitely-not-a-glyph-key', 'title' => 'X', 'url' => '/x', 'short_outcome' => '', 'sector' => ''],
    ]];

    $html = View::make('theme::sections.entry_grid', $view)->render();

    expect($html)->not->toContain('<svg')->not->toContain('<img');
});
