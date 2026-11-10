<?php

use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\StyleProps;
use App\Modules\Theme\Rendering\BlockRenderer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;

/*
 * Section heads, the features block and the split call to action: the partners and features blocks
 * draw the shared centered head (heading, the site's decoration, a description line); every
 * features look takes the band (dark included) and a grid sized by its item count; the top-rule
 * cards are rows on a phone; a call to action may put its text left and pill buttons right.
 */

/** @param  array<string, mixed>  $props */
function shfcView(string $type, array $props): string
{
    app()->setLocale('vi');

    return View::make('theme::sections.'.$type, ['props' => $props])->render();
}

/** @return list<array{title: string, text: string}> */
function shfcItems(int $count): array
{
    return array_map(fn (int $i): array => ['title' => "Mục {$i} ạ", 'text' => "Mô tả {$i} ạ"], range(1, $count));
}

/**
 * @param  array<string, mixed>  $props
 * @return list<string>
 */
function shfcErrors(string $type, array $props): array
{
    app()->setLocale('vi');

    return array_values(Validator::make(['blocks' => [['type' => $type, 'props' => $props]]], ['blocks' => [app(ValidPageJson::class)]])->errors()->get('blocks'));
}

describe('the partners head', function (): void {
    it('is the shared centered head with its description', function (): void {
        $html = shfcView('partners', ['heading' => 'Đối tác tin cậy', 'description' => 'Sự tin tưởng của đối tác ạ', 'logos' => [['name' => 'VinFast']]]);

        expect($html)->toContain('<div class="sf-section-head">')
            ->toContain('<h2 class="sf-section-head__title">Đối tác tin cậy</h2>')
            ->toContain('<p class="sf-section-head__text">Sự tin tưởng của đối tác ạ</p>')
            ->toContain('<span class="sf-section-head__rule" aria-hidden="true"></span>')
            ->not->toContain('partners__heading');
    });

    it('draws no head when the heading and the description are empty', function (): void {
        expect(shfcView('partners', ['logos' => [['name' => 'VinFast']]]))->not->toContain('sf-section-head');
    });

    it('takes the site\'s decoration and the block\'s own choice', function (): void {
        seedTenancy();
        app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'theme', 'key' => 'heading_decor'], ['value' => ['style' => 'flourish']]));
        $render = fn (array $props): string => app(Tenancy::class)->enter(1, fn (): string => app(BlockRenderer::class)->render(['blocks' => [['type' => 'partners', 'props' => ['heading' => 'Đối tác ạ', 'logos' => [['name' => 'SMC']]] + $props]]]));

        expect($render([]))->toContain('sf-decor--flourish')->not->toContain('sf-section-head__rule')
            ->and($render(['heading_decor' => 'none']))->not->toContain('sf-decor')->not->toContain('sf-section-head__rule');
    });

    it('offers a description and the heading decoration in the block schema', function (): void {
        $schema = app(ComponentRegistry::class)->schemaFor('partners');

        expect($schema['description'])->toBe(['type' => 'textarea'])
            ->and($schema['heading_decor'])->toBe(StyleProps::SCHEMA['heading_decor'])
            ->and(shfcErrors('partners', ['heading' => 'Đối tác', 'description' => 'Dòng mô tả', 'heading_decor' => 'flourish', 'logos' => []]))->toBe([]);
    });
});

describe('the features block', function (): void {
    it('draws the default look in a band with the shared head and plain cards', function (): void {
        $html = shfcView('features', ['heading' => 'Lĩnh vực khách hàng', 'description' => 'Các ngành ạ', 'items' => shfcItems(4)]);

        expect($html)->toContain('<section class="sf-band sf-band--none sf-features sf-features--cards">')
            ->toContain('<h2 class="sf-section-head__title">Lĩnh vực khách hàng</h2>')
            ->toContain('<p class="sf-section-head__text">Các ngành ạ</p>')
            ->toContain('<ul class="sf-grid sf-grid--4p">')
            ->and(substr_count($html, '<li class="sf-fcard">'))->toBe(4)
            ->and(substr_count($html, '<h3 class="sf-fcard__title">'))->toBe(4)
            ->and($html)->toContain('<p class="sf-fcard__text">Mô tả 1 ạ</p>')
            ->not->toContain('features__list')
            ->not->toContain('sf-fcard__icon');
    });

    it('puts a known glyph in a tile beside the text and skips an unknown one', function (): void {
        $html = shfcView('features', ['items' => [['title' => 'Máy phay', 'icon' => 'wrench'], ['title' => 'Máy lạ', 'icon' => 'no-such-glyph']]]);

        expect(substr_count($html, '<span class="sf-fcard__icon">'))->toBe(1)
            ->and($html)->toContain('<div class="sf-fcard__body">');
    });

    it('paints the band it is given with either look', function (string $variant, string $class): void {
        foreach (['dark', 'tint', 'surface'] as $bg) {
            expect(shfcView('features', ['bg' => $bg, 'variant' => $variant, 'items' => shfcItems(3)]))
                ->toContain("<section class=\"sf-band sf-band--{$bg} {$class}\">");
        }

        expect(shfcView('features', ['bg' => 'bogus', 'variant' => $variant, 'items' => shfcItems(3)]))
            ->toContain("<section class=\"sf-band sf-band--none {$class}\">");
    })->with([
        'default' => ['default', 'sf-features sf-features--cards'],
        'top-rule' => ['top-rule', 'sf-features'],
    ]);

    it('sizes the grid by the item count', function (string $variant, int $count, string $grid): void {
        expect(shfcView('features', ['variant' => $variant, 'items' => shfcItems($count)]))->toContain("<ul class=\"sf-grid {$grid}\">");
    })->with([
        'two top-rule cards fill two columns' => ['top-rule', 2, 'sf-grid--2'],
        'three top-rule cards' => ['top-rule', 3, 'sf-grid--3'],
        'six top-rule cards' => ['top-rule', 6, 'sf-grid--3s'],
        'four top-rule cards' => ['top-rule', 4, 'sf-grid--3s'],
        'two plain cards' => ['default', 2, 'sf-grid--2'],
        'three plain cards' => ['default', 3, 'sf-grid--3'],
        'four plain cards, two on a phone' => ['default', 4, 'sf-grid--4p'],
        'six plain cards' => ['default', 6, 'sf-grid--3s'],
    ]);

    it('keeps the top-rule card and wraps its text so a phone can lay it out as a row', function (): void {
        $html = shfcView('features', ['variant' => 'top-rule', 'items' => [['title' => 'Thiết kế', 'text' => 'Bản vẽ', 'icon' => 'box']]]);

        expect($html)->toContain('<li class="sf-feature">')
            ->toContain('<span class="sf-feature__icon">')
            ->toContain('<div class="sf-feature__body">')
            ->toContain('<h3 class="sf-feature__title">Thiết kế</h3>')
            ->toContain('<p class="sf-feature__text">Bản vẽ</p>');
    });

    it('lays the cards out as the boards show in the stylesheet', function (): void {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        // Top-rule: a row with the rule on the left below 768px, a centered card with the rule on top from it.
        expect($css)->toMatch('/\.sf-feature \{\n    flex-direction: row;[^}]*border-left: 0\.25rem solid var\(--color-primary\);/')
            ->toMatch('/@media \(min-width: 768px\) \{\n    \.sf-feature \{\n        flex-direction: column;[^}]*border-top: 0\.25rem solid var\(--color-primary\);/')
            // The ring and the glyph: a light grey ring with a dark glyph.
            ->toMatch('/\.sf-feature__icon \{[^}]*background: color-mix\(in srgb, var\(--color-dark\) 12%, var\(--color-surface\)\);[^}]*color: var\(--color-dark-raised, var\(--color-dark\)\);/')
            // A dark band drops the card and lights the glyph.
            ->toMatch('/\.sf-band--dark :is\(\.sf-feature, \.sf-fcard\) \{[^}]*background: transparent;/')
            ->toMatch('/\.sf-band--dark :is\(\.sf-feature__icon, \.sf-fcard__icon\) \{[^}]*color: var\(--color-primary-on-dark\);/')
            // The new grids.
            ->toMatch('/\.sf-grid--2 \{\n        grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/')
            ->toMatch('/\.sf-grid--3p,\n\.sf-grid--4p \{\n    grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/');
    });
});

describe('section title sizes', function (): void {
    it('gives the about and call-to-action titles the section title size on every screen', function (): void {
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        expect($css)->toMatch('/\.sf-section-head__title \{[^}]*font-size: 1\.875rem;/')
            ->toMatch('/\.about__heading \{\n    font-size: 1\.875rem;\n    line-height: 2\.25rem;\n    font-weight: 700;/')
            ->toMatch('/\.cta h2 \{\n    font-size: 1\.875rem;\n    line-height: 2\.25rem;/');
    });
});

describe('the split call to action', function (): void {
    it('puts the text left and pill buttons right', function (): void {
        $html = shfcView('cta', [
            'variant' => 'split', 'heading' => 'Hợp tác cùng An Vinh', 'text' => 'Doanh nghiệp cần gia công ạ',
            'button_label' => 'Tôi cần gia công', 'button_url' => '/lien-he',
            'button2_label' => 'Đăng ký nhà cung cấp', 'button2_url' => '/lien-he',
        ]);

        expect($html)->toContain('<section class="cta cta--split">')
            ->toContain('<div class="sf-container cta__row">')
            ->toContain('<div class="cta__copy">')
            ->toContain('<h2 class="cta__title">Hợp tác cùng An Vinh</h2>')
            ->toContain('<p class="cta__text">Doanh nghiệp cần gia công ạ</p>')
            ->toContain('<div class="cta__actions">')
            ->toContain('<a class="cta__button cta__button--pill" href="/lien-he">Tôi cần gia công</a>')
            ->toContain('<a class="cta__button cta__button--pill cta__button--outline" href="/lien-he">Đăng ký nhà cung cấp</a>');
    });

    it('keeps one pill in the button row and draws no row without buttons', function (): void {
        $one = shfcView('cta', ['variant' => 'split', 'heading' => 'Một nút', 'button_label' => 'Gọi', 'button_url' => '/lien-he']);
        $none = shfcView('cta', ['variant' => 'split', 'heading' => 'Không nút']);

        expect($one)->toContain('<div class="cta__actions">')->and(substr_count($one, 'cta__button--pill'))->toBe(1)
            ->and($none)->not->toContain('cta__actions')->toContain('cta--split');
    });

    it('ignores the stats beside the text, like the rounded look', function (): void {
        $html = shfcView('cta', ['variant' => 'split', 'heading' => 'H', 'side' => 'stats', 'side_items' => [['value' => 5, 'label' => 'Năm']]]);

        expect($html)->toContain('cta--split')->not->toContain('cta--side')->not->toContain('cta__stats');
    });

    it('is offered and saved as a variant, and an unknown one draws the default band', function (): void {
        expect(app(ComponentRegistry::class)->schemaFor('cta')['variant']['options'])->toBe(['default', 'rounded', 'split'])
            ->and(shfcErrors('cta', ['variant' => 'split', 'heading' => 'H']))->toBe([])
            ->and(shfcErrors('cta', ['variant' => 'sideways', 'heading' => 'H']))->not->toBe([])
            ->and(shfcView('cta', ['variant' => 'sideways', 'heading' => 'H']))->toContain('<section class="cta">');
    });

    it('takes the cross pattern behind it', function (): void {
        $html = app(BlockRenderer::class)->render(['blocks' => [['type' => 'cta', 'props' => ['variant' => 'split', 'heading' => 'H', 'extra_style' => ['pattern-cross']]]]]);
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        expect($html)->toContain('sf-s sf-s--x-pattern-cross')->toContain('cta cta--split')
            ->and($css)->toContain('.sf-s--x-pattern-cross :is(.cta:not(.cta--side), .cta__panel)::before');
    });
});
