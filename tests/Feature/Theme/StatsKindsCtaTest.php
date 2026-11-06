<?php

use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Rendering\BlockRenderer;
use App\Modules\Theme\Rendering\PageRenderer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;

/*
 * Stats kinds (a counted number, years since a start year, a year as is), the stats look, a second
 * call-to-action button, the cross pattern behind it, and up to three stats beside it.
 */

afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $props */
function kindsStats(array $props, string $type = 'stats'): string
{
    app()->setLocale('vi');

    return View::make('theme::sections.'.$type, ['props' => $props])->render();
}

/**
 * @param  array<string, mixed>  $props
 * @return list<string>
 */
function kindsErrors(array $props, string $type, string $locale = 'vi'): array
{
    app()->setLocale($locale);

    return array_values(Validator::make(['blocks' => [['type' => $type, 'props' => $props]]], ['blocks' => [app(ValidPageJson::class)]])->errors()->get('blocks'));
}

describe('stats kinds', function (): void {
    it('prints this year minus the start year for years, in the site timezone', function (): void {
        // 18:30 UTC on 31 December is already 1 January in Ho Chi Minh City (GMT+7).
        Carbon::setTestNow('2026-12-31 18:30:00 UTC');
        $html = kindsStats(['items' => [['kind' => 'years', 'value' => 2014, 'suffix' => '+', 'label' => 'Năm kinh nghiệm']]]);

        expect($html)->toContain('<span>13</span>')->not->toContain('data-count')->not->toContain('sf-stat__suffix')
            ->and($html)->toContain('<span class="sr-only">13</span>');

        Carbon::setTestNow('2026-12-31 16:30:00 UTC');
        expect(kindsStats(['items' => [['kind' => 'years', 'value' => 2014, 'label' => 'Năm']]]))->toContain('<span>12</span>');
    });

    it('prints a year as it is, without a thousands separator or a count-up', function (): void {
        Carbon::setTestNow('2026-06-01 00:00:00 UTC');
        $html = kindsStats(['items' => [['kind' => 'year', 'value' => 2019, 'suffix' => 'm²', 'label' => 'Năm thành lập']]]);

        expect($html)->toContain('<span>2019</span>')->not->toContain('2.019')->not->toContain('data-count')->not->toContain('m²');
    });

    it('hides an item whose year is outside 1900 up to this year', function (string $kind, int $value): void {
        Carbon::setTestNow('2026-06-01 00:00:00 UTC');

        expect(trim(kindsStats(['items' => [['kind' => $kind, 'value' => $value, 'label' => 'Ẩn']]])))->toBe('');
    })->with([
        'years before 1900' => ['years', 1899],
        'years in the future' => ['years', 2027],
        'year before 1900' => ['year', 1899],
        'year in the future' => ['year', 2027],
        'years, zero' => ['years', 0],
    ]);

    it('keeps the other items when one year is out of range, and accepts the bounds', function (): void {
        Carbon::setTestNow('2026-06-01 00:00:00 UTC');
        $html = kindsStats(['items' => [
            ['kind' => 'years', 'value' => 3000, 'label' => 'Ẩn'],
            ['kind' => 'years', 'value' => 1900, 'label' => 'Cổ'],
            ['kind' => 'year', 'value' => 2026, 'label' => 'Nay'],
            ['value' => 4300, 'suffix' => ' m²', 'label' => 'Đội ngũ kỹ sư'],
        ]]);

        expect(substr_count($html, '<li'))->toBe(3)->and($html)->toContain('<span>126</span>')->toContain('<span>2026</span>')->toContain('data-count="4300">4.300')->not->toContain('Ẩn');
    });

    it('treats an unknown or missing kind as a number', function (mixed $kind): void {
        $html = kindsStats(['items' => [['kind' => $kind, 'value' => 2000, 'suffix' => '+', 'label' => 'Công trình']] + []]);

        expect($html)->toContain('<span data-count="2000">2.000</span>')->toContain('<span class="sf-stat__suffix">+</span>');
    })->with(['unknown' => ['decade'], 'empty' => [''], 'not a string' => [7], 'null' => [null]]);

    it('names the column count up to eight items', function (int $count): void {
        $html = kindsStats(['items' => array_fill(0, $count, ['value' => 3, 'label' => 'x'])]);

        expect($html)->toContain("sf-stats__list--{$count}");
    })->with([1, 4, 5, 8]);

    it('draws the floating look only when asked for it, and any other value is the plain band', function (): void {
        expect(kindsStats(['variant' => 'floating', 'items' => [['value' => 1]]]))->toContain('class="sf-band sf-band--none sf-stats sf-stats--floating"')
            ->and(kindsStats(['variant' => 'weird', 'items' => [['value' => 1]]]))->toContain('class="sf-band sf-band--none sf-stats"');
    });

    it('refuses an unknown kind or look when saving, with a plain message in every language', function (string $locale): void {
        foreach ([['items' => [['kind' => 'decade', 'value' => 1]]], ['variant' => 'huge', 'items' => []]] as $props) {
            $errors = kindsErrors($props, 'stats', $locale);
            expect($errors)->toHaveCount(1)->and($errors[0])->not->toContain('entries.');
        }
        expect(kindsErrors(['variant' => 'floating', 'items' => [['kind' => 'years', 'value' => 2014], ['kind' => 'year', 'value' => 2019], ['kind' => 'number', 'value' => 3]]], 'stats', $locale))->toBe([]);
    })->with(['vi', 'en', 'ja', 'zh']);

    it('loads the count-up script only for a counted number', function (): void {
        $renderer = new ReflectionMethod(PageRenderer::class, 'countsUp');
        $counts = fn (array $blocks): bool => $renderer->invoke(null, ['blocks' => $blocks]);

        expect($counts([['type' => 'stats', 'props' => ['items' => [['kind' => 'years', 'value' => 2014], ['kind' => 'year', 'value' => 2019]]]]]))->toBeFalse()
            ->and($counts([['type' => 'stats', 'props' => ['items' => [['kind' => 'year', 'value' => 2019], ['value' => 5]]]]]))->toBeTrue()
            ->and($counts([['type' => 'cta', 'props' => ['side' => 'stats', 'side_items' => [['value' => 5]]]]]))->toBeTrue()
            ->and($counts([['type' => 'cta', 'props' => ['side' => 'stats', 'variant' => 'rounded', 'side_items' => [['value' => 5]]]]]))->toBeFalse()
            ->and($counts([['type' => 'cta', 'props' => ['side' => 'none', 'side_items' => [['value' => 5]]]]]))->toBeFalse();
    });
});

describe('cta second button and pattern', function (): void {
    beforeEach(fn () => seedTenancy());

    /** @param  array<string, mixed>  $props */
    function kindsCta(array $props): string
    {
        app()->setLocale('vi');

        return app(BlockRenderer::class)->render(['blocks' => [['type' => 'cta', 'props' => $props]]]);
    }

    it('draws a second button with its typed action beside the first', function (): void {
        $html = kindsCta(['heading' => 'Cần báo giá?', 'button_label' => 'Liên hệ', 'button_url' => '/lien-he', 'button2_label' => 'Hotline: 0915 66 83 83', 'button2_url_do' => ['k' => 'tel', 'phone' => '0915668383']]);

        expect($html)->toContain('<div class="cta__actions">')
            ->and($html)->toContain('<a class="cta__button" href="/lien-he">Liên hệ</a>')
            ->and($html)->toContain('<a class="cta__button cta__button--outline" href="tel:0915668383">Hotline: 0915 66 83 83</a>');

        $rounded = kindsCta(['variant' => 'rounded', 'button_label' => 'Liên hệ', 'button_url' => '/lien-he', 'button2_label' => 'Gọi', 'button2_url_do' => ['k' => 'tel', 'phone' => '0915668383']]);
        expect($rounded)->toContain('cta__button cta__button--pill cta__button--outline" href="tel:0915668383"');
    });

    it('draws a second button that downloads a document of this site', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            Media::query()->create([
                'collection' => 'library', 'slug' => 'ho-so', 'hash' => hash('sha256', 'a/library/ho-so-aaaaaaaaaaaa.pdf'),
                'storage_key' => 'a/library/ho-so-aaaaaaaaaaaa.pdf', 'disk' => 'r2', 'mime' => 'application/pdf', 'size' => 8_400_000, 'original_filename' => 'Hồ sơ.pdf',
            ]);

            expect(kindsCta(['button2_label' => 'Tải hồ sơ năng lực (PDF)', 'button2_url_do' => ['k' => 'file', 'file' => 'a/library/ho-so-aaaaaaaaaaaa.pdf']]))
                ->toMatch('~<a class="cta__button cta__button--outline" href="[^"]+/a/library/ho-so-aaaaaaaaaaaa\.pdf" download="">Tải hồ sơ năng lực \(PDF\)</a>~');
        });
    });

    it('draws no second button without a label or a link that resolves', function (array $props): void {
        expect(kindsCta(['button_label' => 'Liên hệ', 'button_url' => '/lien-he'] + $props))->not->toContain('cta__actions')->not->toContain('cta__button--outline');
    })->with([
        'no label' => [['button2_url_do' => ['k' => 'tel', 'phone' => '0915668383']]],
        'no link' => [['button2_label' => 'Gọi']],
        'an unsafe link' => [['button2_label' => 'Gọi', 'button2_url' => 'javascript:alert(1)']],
    ]);

    it('prints the pattern class of a listed look and ignores an unlisted one', function (): void {
        expect(kindsCta(['heading' => 'Hồ sơ', 'extra_style' => 'pattern-cross']))->toContain('class="sf-s sf-s--x-pattern-cross"')
            ->and(kindsCta(['heading' => 'Hồ sơ', 'extra_style' => 'none']))->not->toContain('sf-s')
            ->and(kindsCta(['heading' => 'Hồ sơ', 'extra_style' => 'glitter']))->not->toContain('sf-s')->not->toContain('glitter')
            ->and(kindsCta(['heading' => 'Hồ sơ', 'extra_style' => ['pattern-cross']]))->not->toContain('sf-s');
    });

    it('prints the pattern only on a block that declares the look', function (): void {
        $html = app(BlockRenderer::class)->render(['blocks' => [['type' => 'richtext', 'props' => ['heading' => 'Một', 'extra_style' => 'pattern-cross']]]]);

        expect($html)->not->toContain('pattern-cross');
    });

    it('refuses an unlisted pattern when saving, in every language', function (string $locale): void {
        $errors = kindsErrors(['heading' => 'Hồ sơ', 'extra_style' => 'glitter'], 'cta', $locale);

        expect($errors)->toHaveCount(1)->and($errors[0])->not->toContain('entries.')
            ->and(kindsErrors(['heading' => 'Hồ sơ', 'extra_style' => 'pattern-cross', 'button2_label' => 'Gọi', 'button2_url_do' => ['k' => 'tel', 'phone' => '0915668383']], 'cta', $locale))->toBe([]);
    })->with(['vi', 'en', 'ja', 'zh']);

    it('refuses a second-button action that is not valid', function (): void {
        $errors = kindsErrors(['button2_label' => 'Gọi', 'button2_url_do' => ['k' => 'tel', 'phone' => 'abc']], 'cta');

        expect($errors)->toHaveCount(1);
    });
});

describe('cta stats beside the text', function (): void {
    beforeEach(fn () => seedTenancy());

    /** @return array<string, mixed> */
    function kindsSide(array $extra = []): array
    {
        return $extra + ['heading' => 'Hồ sơ năng lực An Vinh', 'text' => 'Giới thiệu công ty.', 'button_label' => 'Tải hồ sơ', 'button_url' => '/ho-so', 'side' => 'stats', 'side_items' => [
            ['kind' => 'year', 'value' => 2019, 'label' => 'Năm thành lập'],
            ['value' => 3, 'label' => 'Nhà máy'],
            ['value' => 4300, 'suffix' => ' m²', 'label' => 'Nhà xưởng'],
        ]];
    }

    it('draws the text and up to three stats in one flat block, with the shared stat items', function (): void {
        Carbon::setTestNow('2026-06-01 00:00:00 UTC');
        $html = kindsCta(kindsSide());

        expect($html)->toContain('<section class="cta cta--side">')->toContain('<div class="cta__panel">')->toContain('<ul class="sf-stats__list cta__stats">')
            ->and(substr_count($html, '<li class="sf-stat">'))->toBe(3)
            ->and($html)->toContain('<span>2019</span>')->toContain('data-count="4300">4.300')->toContain('<span class="sf-stat__label">Nhà máy</span>')
            ->and($html)->toContain('<a class="cta__button" href="/ho-so">Tải hồ sơ</a>');
    });

    it('draws the second button and the pattern with the stats too', function (): void {
        $html = kindsCta(kindsSide(['button2_label' => 'Gọi', 'button2_url_do' => ['k' => 'tel', 'phone' => '0915668383'], 'extra_style' => 'pattern-cross']));

        expect($html)->toContain('class="sf-s sf-s--x-pattern-cross"')->toContain('cta__button cta__button--outline" href="tel:0915668383"')->toContain('cta--side');
    });

    it('ignores the stats with the rounded look, and draws today\'s block for side none', function (): void {
        foreach ([kindsSide(['variant' => 'rounded']), kindsSide(['side' => 'none']), kindsSide(['side' => 'bogus'])] as $props) {
            $html = kindsCta($props);
            expect($html)->not->toContain('cta--side')->not->toContain('sf-stat');
        }
        expect(kindsCta(kindsSide(['variant' => 'rounded'])))->toContain('cta cta--rounded');
    });

    it('draws today\'s block when no side stat is left to show', function (): void {
        Carbon::setTestNow('2026-06-01 00:00:00 UTC');

        expect(kindsCta(kindsSide(['side_items' => [['kind' => 'years', 'value' => 3000, 'label' => 'Ẩn']]])))->not->toContain('cta--side');
    });

    it('draws no more than three side stats even if more were stored', function (): void {
        $html = kindsCta(kindsSide(['side_items' => array_fill(0, 5, ['value' => 7, 'label' => 'Bảy'])]));

        expect(substr_count($html, '<li class="sf-stat">'))->toBe(3);
    });

    it('refuses more than three side stats or an unknown choice when saving, in every language', function (string $locale): void {
        $tooMany = kindsErrors(kindsSide(['side_items' => array_fill(0, 4, ['value' => 7, 'label' => 'Bảy'])]), 'cta', $locale);
        expect($tooMany)->toHaveCount(1)->and($tooMany[0])->toContain('3')->not->toContain('entries.');

        $unknown = kindsErrors(kindsSide(['side' => 'cards']), 'cta', $locale);
        $badKind = kindsErrors(kindsSide(['side_items' => [['kind' => 'decade', 'value' => 1]]]), 'cta', $locale);
        expect($unknown)->toHaveCount(1)->and($badKind)->toHaveCount(1)
            ->and(kindsErrors(kindsSide(), 'cta', $locale))->toBe([]);
    })->with(['vi', 'en', 'ja', 'zh']);
});
