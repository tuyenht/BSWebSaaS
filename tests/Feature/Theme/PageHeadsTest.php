<?php

use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SectionProps;
use App\Modules\Theme\Theming\HeadingDecor;
use Illuminate\Support\Facades\View;

/*
 * The heads of a page: the title band (photo, or dark with the diagonal pattern, with an optional gold
 * tick), the breadcrumb's separator and a section head's alignment and row-long rule.
 */

beforeEach(function (): void {
    seedTenancy();
    app()->setLocale('vi');
});

/** @param  array<string, mixed>  $props */
function headsBand(array $props): string
{
    return View::make('theme::sections.page_header', ['props' => $props + ['title' => 'Cảm ơn bạn', 'subtitle' => 'Đã nhận ạ']])->render();
}

/** @param  array<string, mixed>  $props */
function headsFaq(array $props = []): string
{
    return View::make('theme::sections.faq', ['props' => ['heading' => 'Câu hỏi thường gặp', 'description' => 'Mô tả ngắn', 'items' => [['q' => 'Ổn không ạ', 'a' => 'Đáp ạ']]] + $props])->render();
}

describe('the title band', function (): void {
    it('is plain dark by default and gets the diagonal pattern only when the page sets it', function (): void {
        expect(headsBand([]))->toContain('<section class="page-band">')->not->toContain('--pattern')
            ->not->toContain('page-band__mark')
            ->and(headsBand(['pattern' => 'on']))->toContain('<section class="page-band page-band--pattern">');
    });

    it('keeps the plain photo band when it has a photo, even when the pattern is on', function (): void {
        $photo = headsBand(['image_url' => 'https://files.baoson.net/band.jpg']);
        $photoOn = headsBand(['image_url' => 'https://files.baoson.net/band.jpg', 'pattern' => 'on']);

        expect($photo)->toContain('<section class="page-band">')->toContain('page-band__overlay')
            ->and($photoOn)->toContain('<section class="page-band">')->not->toContain('--pattern');
    });

    it('draws the gold tick above the title only when asked, hidden from screen readers', function (): void {
        $html = headsBand(['mark' => 'check', 'pattern' => 'on']);

        expect($html)->toContain('page-band page-band--pattern page-band--mark')
            ->toMatch('#<span class="page-band__mark" aria-hidden="true">.*<svg.*</svg>.*</span>\s*<h2 class="page-band__title">#s')
            ->and(headsBand(['mark' => 'none']))->not->toContain('page-band__mark');
    });

    it('lists its new choices in the schema, defaulting to the old look (no pattern, no tick)', function (): void {
        $schema = app(ComponentRegistry::class)->schemaFor('page_header');

        expect($schema['pattern'])->toMatchArray(['type' => 'enum', 'options' => SectionProps::BAND_PATTERNS, 'default' => 'off'])
            ->and($schema['mark'])->toMatchArray(['type' => 'enum', 'options' => SectionProps::BAND_MARKS, 'default' => 'none'])
            ->and($schema)->not->toHaveKey('heading_align');
    });
});

describe('the breadcrumb', function (): void {
    it('closes each level but the last with the separator, so a wrapped trail never starts a line with it', function (): void {
        $css = file_get_contents(resource_path('css/storefront.css'));

        expect($css)->toContain(".sf-crumbs__item:not(:last-child)::after {\n    content: \"/\";")
            ->not->toContain('.sf-crumbs__item + .sf-crumbs__item::before');
    });
});

describe('a section head', function (): void {
    it('is centred by default', function (): void {
        expect(headsFaq())->toContain('<div class="sf-section-head">')->not->toContain('sf-section-head--left');
    });

    it('sits at the left edge when the block says so, and its shape follows unless the site keeps it in the middle', function (): void {
        $html = headsFaq(['heading_align' => 'left', 'heading_decor' => 'flourish']);
        $odd = headsFaq(['heading_align' => 'sideways']);

        expect($html)->toContain('<div class="sf-section-head sf-section-head--left">')
            ->toContain('sf-decor sf-decor--flourish sf-decor--left')
            ->and($odd)->not->toContain('sf-section-head--left');

        $decor = app(HeadingDecor::class);
        expect($decor->classes('flourish', 'left'))->toBe('sf-decor sf-decor--flourish sf-decor--left')
            ->and($decor->classes('flourish', 'center'))->toBe('sf-decor sf-decor--flourish')
            ->and($decor->classes('flourish'))->toBe('sf-decor sf-decor--flourish');
    });

    it('draws the one gold rule across the row for the line choice, and none for none', function (): void {
        $line = headsFaq(['heading_align' => 'left', 'heading_decor' => 'line']);
        $none = headsFaq(['heading_decor' => 'none']);

        expect($line)->toContain('<span class="sf-decor sf-decor--line" aria-hidden="true"></span>')
            ->and($none)->not->toContain('sf-decor')->not->toContain('sf-section-head__rule');
    });

    it('has a CSS rule for the line that is not a mask shape', function (): void {
        $css = file_get_contents(resource_path('css/storefront.css'));

        expect($css)->toContain(".sf-decor--line {\n    width: 100%;")
            ->and(HeadingDecor::STYLES)->not->toContain('line')
            ->and(HeadingDecor::BLOCK_CHOICES)->toContain('line');
    });
});
