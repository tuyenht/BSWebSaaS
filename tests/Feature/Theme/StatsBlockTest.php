<?php

use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCards;

/*
 * The stats block: big numbers with a label. The server prints the final number formatted the
 * Vietnamese way (so print, search and no-JS are right); the visible number is hidden from screen
 * readers and a visually hidden copy carries the final text; the count-up script loads only on a
 * page whose stats block holds a number.
 */

/**
 * @param  array<string, mixed>  $props
 * @return list<string>
 */
function statsErrors(array $props): array
{
    app()->setLocale('vi');

    return array_values(Validator::make(['blocks' => [['type' => 'stats', 'props' => $props]]], ['blocks' => [app(ValidPageJson::class)]])->errors()->get('blocks'));
}

/** @param  array<string, mixed>  $props */
function statsBlock(array $props): string
{
    app()->setLocale('vi');

    return View::make('theme::sections.stats', ['props' => $props])->render();
}

describe('validation', function (): void {
    it('accepts whole numbers, blank values and a full list', function (array $props): void {
        expect(statsErrors($props))->toBe([]);
    })->with([
        'default' => [['heading' => '', 'items' => [['value' => 15, 'suffix' => '+', 'label' => 'Năm'], ['value' => 98, 'suffix' => '%', 'label' => 'Hài lòng']]]],
        'zero' => [['items' => [['value' => 0, 'label' => 'Mới']]]],
        'digits as typed' => [['items' => [['value' => '2000', 'label' => 'A']]]],
        'blank' => [['items' => [['value' => '', 'label' => 'A']]]],
        'upper bound' => [['items' => [['value' => 999_999_999_999]]]],
        'eight items' => [['items' => array_fill(0, 8, ['value' => 1, 'label' => 'A'])]],
        'no props' => [[]],
    ]);

    it('refuses a number that is negative, fractional, too big or not a number', function (mixed $value): void {
        $errors = statsErrors(['items' => [['value' => $value, 'label' => 'A']]]);
        expect($errors)->toHaveCount(1)->and($errors[0])->toContain('stats')->not->toContain('entries.');
    })->with([-1, 1.5, '12.5', '-3', 'abc', 1_000_000_000_000]);

    it('refuses more than eight items, in plain words', function (): void {
        $errors = statsErrors(['items' => array_fill(0, 9, ['value' => 1, 'label' => 'A'])]);
        expect($errors)->toHaveCount(1)->and($errors[0])->toContain('8')->toContain('stats')->not->toContain('entries.');
    });

    it('has its error messages in every language file', function (): void {
        foreach (['en', 'vi', 'ja', 'zh'] as $locale) {
            $file = json_decode((string) file_get_contents(base_path("lang/{$locale}.json")), true);
            expect($file)->toHaveKeys(['entries.err_block_list_max', 'entries.err_block_number_invalid']);
        }
    });
});

describe('render', function (): void {
    it('prints the final formatted number, the suffix and a hidden copy for screen readers', function (): void {
        $html = statsBlock(['items' => [['value' => 2000, 'suffix' => '+', 'label' => 'Công trình']]]);

        expect($html)->toContain('<span class="sf-stat__value" aria-hidden="true"><span data-count="2000">2.000</span><span class="sf-stat__suffix">+</span></span>')
            ->and($html)->toContain('<span class="sr-only">2.000+</span>')
            ->and($html)->toContain('<span class="sf-stat__label">Công trình</span>')
            ->and($html)->toContain('sf-stats__list--1');
    });

    it('trims a long suffix, formats big numbers and accepts typed digits', function (): void {
        $html = statsBlock(['items' => [['value' => '1234567', 'suffix' => 'abcdefghijkl', 'label' => 'X']]]);

        expect($html)->toContain('data-count="1234567">1.234.567</span>')->toContain('abcdefgh</span>')->not->toContain('abcdefghi');
    });

    it('skips items with neither a number nor a label, and renders nothing when none is left', function (): void {
        expect(statsBlock(['items' => [['value' => '', 'suffix' => '+', 'label' => ' '], ['suffix' => '%']]]))->not->toContain('sf-stats')
            ->and(trim(statsBlock([])))->toBe('');

        $html = statsBlock(['items' => [['label' => 'Chỉ chữ'], ['value' => 5, 'label' => ''], ['value' => '', 'label' => '']]]);
        expect($html)->toContain('Chỉ chữ')->toContain('data-count="5"')->not->toContain('data-count=""')
            ->and(substr_count($html, '<li'))->toBe(2);
    });

    it('caps at eight items, names the column count by the items shown, and escapes text', function (): void {
        $html = statsBlock(['heading' => '<b>Số liệu</b>', 'items' => array_fill(0, 10, ['value' => 3, 'label' => '<i>x</i>'])]);

        expect(substr_count($html, '<li'))->toBe(8)
            ->and($html)->toContain('sf-stats__list--8')
            ->and($html)->toContain('&lt;b&gt;Số liệu&lt;/b&gt;')->not->toContain('<b>Số liệu')
            ->and($html)->toContain('&lt;i&gt;x&lt;/i&gt;');
    });

    it('prints the section heading', function (): void {
        expect(statsBlock(['heading' => 'Số liệu', 'items' => [['value' => 1]]]))->toContain('Số liệu');
    });
});

describe('on a page', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        $this->siteId = StorefrontCards::site();
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
            }
        });
    });

    /** @param  list<array<string, mixed>>  $blocks */
    function statsPage(string $path, array $blocks): string
    {
        app(Tenancy::class)->enter(test()->siteId, fn () => StorefrontCards::page($path, 'Giới thiệu', $blocks));

        return test()->get('http://mau.platform.test'.$path)->assertOk()->getContent();
    }

    it('loads the count-up script on a page with a number', function (): void {
        $html = statsPage('/so-lieu', [['type' => 'stats', 'props' => ['items' => [['value' => 15, 'suffix' => '+', 'label' => 'Năm']]]]]);

        expect($html)->toContain('<script type="module" src="/resources/js/sf-stats.js"></script>')->toContain('data-count="15"');
    });

    it('loads no count-up script without a stats number', function (array $blocks): void {
        expect(statsPage('/khong', $blocks))->not->toContain('sf-stats.js');
    })->with([
        'no stats block' => [[['type' => 'richtext', 'props' => ['heading' => 'Một']]]],
        'labels only' => [[['type' => 'stats', 'props' => ['items' => [['label' => 'Chỉ chữ']]]]]],
        'blank and negative values' => [[['type' => 'stats', 'props' => ['items' => [['value' => '', 'label' => 'A'], ['value' => -4, 'label' => 'B']]]]]],
        'a number only past the eighth item' => [[['type' => 'stats', 'props' => ['items' => [...array_fill(0, 8, ['label' => 'Chữ']), ['value' => 5, 'label' => 'Số']]]]]],
        'a number over the maximum' => [[['type' => 'stats', 'props' => ['items' => [['value' => 10 ** 13, 'label' => 'Lớn']]]]]],
    ]);

    it('shows and counts a number typed with leading zeros, as the save check accepts it', function (): void {
        $html = statsPage('/so-0', [['type' => 'stats', 'props' => ['items' => [['value' => '0000000000005', 'label' => 'Năm']]]]]);

        expect($html)->toContain('data-count="5"')->toContain('sf-stats.js');
    });

    it('costs no extra queries compared with a plain page', function (): void {
        statsPage('/thuong', [['type' => 'richtext', 'props' => ['heading' => 'Một']]]);
        statsPage('/co-so-lieu', [['type' => 'stats', 'props' => ['items' => [['value' => 15, 'label' => 'A'], ['value' => 20, 'label' => 'B']]]]]);

        $plain = countQueries(fn () => test()->get('http://mau.platform.test/thuong')->assertOk());
        $stats = countQueries(fn () => test()->get('http://mau.platform.test/co-so-lieu')->assertOk());

        expect($stats)->toBe($plain);
    });
});
