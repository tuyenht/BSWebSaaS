<?php

use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCards;

/*
 * The hero slider block: server-rendered slides (APG carousel markup), at most five, controls
 * hidden until the script shows them, slide 1 as the LCP image and the others' photos kept out of
 * the first load, h2 slide headings under the layout's h1, clamped timing and overlay, safe links,
 * and the slider script + media preconnect only on the pages that need them.
 */

/** @param list<array<string, mixed>> $slides */
function heroSlider(array $slides, array $props = [], array $srcsets = []): string
{
    app()->setLocale('vi');

    return View::make('theme::sections.hero_slider', ['props' => ['slides' => $slides] + $props, 'srcsets' => $srcsets])->render();
}

function threeSlides(): array
{
    return [
        ['image_url' => 'https://files.test/s/a.webp?w=1280', 'heading' => 'Một', 'subheading' => 'Dòng một'],
        ['image_url' => 'https://files.test/s/b.webp?w=1280', 'heading' => 'Hai'],
        ['image_url' => 'https://files.test/s/c.webp?w=1280', 'heading' => 'Ba'],
    ];
}

it('renders each slide as a labelled group with slide 1 as the high-priority image', function (): void {
    $html = heroSlider(threeSlides(), ['cta_label' => 'Báo giá', 'cta_url' => '/lien-he'], [
        'https://files.test/s/a.webp?w=1280' => 'https://files.test/s/a.webp?w=640 640w, https://files.test/s/a.webp?w=1280 1280w',
    ]);

    expect(substr_count($html, 'role="group" aria-roledescription="slide"'))->toBe(3)
        ->and($html)->toContain('aria-label="Ảnh 1/3"')->toContain('aria-label="Ảnh 3/3"')
        ->and($html)->toContain('aria-roledescription="carousel"')
        ->and($html)->toMatch('#<img class="sf-slide__img" src="https://files\.test/s/a\.webp\?w=1280" srcset="[^"]+" sizes="100vw" alt="" width="1600" height="900" loading="eager" fetchpriority="high">#')
        ->and($html)->toMatch('#<div class="sf-slide__media" hidden >\s*<img class="sf-slide__img" src="https://files\.test/s/b\.webp\?w=1280" alt="" width="1600" height="900" loading="lazy" decoding="async">#')
        ->and(substr_count($html, '<h2 class="sf-slide__title">'))->toBe(3)
        ->and($html)->not->toContain('<h1')
        ->and(substr_count($html, 'href="/lien-he">Báo giá</a>'))->toBe(3);
});

it('renders the controls hidden, the rotation button first and dot 1 current', function (): void {
    $html = heroSlider(threeSlides(), ['autoplay' => 6]);

    expect($html)->toContain('<div class="sf-slider__controls" hidden>');
    $controls = substr($html, strpos($html, 'sf-slider__controls'), strpos($html, 'sf-slider__viewport') - strpos($html, 'sf-slider__controls'));
    expect(strpos($controls, 'data-sf-pause'))->toBeLessThan(strpos($controls, 'data-sf-prev'))
        ->and($controls)->toContain('data-label-play="Tự chuyển ảnh tiếp"')
        ->and($controls)->toContain('aria-label="Xem ảnh 1" aria-current="true"')
        ->and(substr_count($controls, 'aria-current'))->toBe(1);
});

it('shows at most five slides, skips empty ones and renders nothing without any', function (): void {
    $six = array_map(fn (int $n): array => ['heading' => "Ảnh {$n}"], range(1, 6));
    expect(substr_count(heroSlider($six), 'role="group"'))->toBe(5);

    $withEmpty = [['heading' => 'Có'], ['subheading' => 'chỉ phụ đề'], ['image_url' => '', 'heading' => '  '], ['heading' => 'Cũng có']];
    expect(substr_count(heroSlider($withEmpty), 'role="group"'))->toBe(2);

    expect(trim(heroSlider([])))->toBe('');
});

it('renders one slide as a still band with no controls', function (): void {
    $html = heroSlider([threeSlides()[0]], ['autoplay' => 5]);

    expect($html)->toContain('sf-slider')
        ->and($html)->not->toContain('sf-slider__controls')
        ->and($html)->not->toContain('data-sf-slider');
});

it('clamps the autoplay seconds and the overlay', function (mixed $autoplay, string $interval): void {
    expect(heroSlider(threeSlides(), ['autoplay' => $autoplay]))->toContain('data-interval="'.$interval.'"');
})->with([
    'not a number' => ['abc', '0'],
    'off' => [0, '0'],
    'below the floor' => [3, '5000'],
    'inside' => [8, '8000'],
    'above the cap' => [99, '15000'],
]);

it('keeps the overlay readable', function (mixed $overlay, string $opacity): void {
    expect(heroSlider(threeSlides(), ['overlay' => $overlay]))->toContain('style="opacity:'.$opacity.'"');
})->with([
    'too light' => [30, '0.55'],
    'default' => [null, '0.6'],
    'too dark' => [95, '0.8'],
]);

it('drops a button whose link is unsafe and escapes slide text', function (string $url): void {
    $html = heroSlider([['heading' => '<script>alert(1)</script>']], ['cta_label' => 'Đi', 'cta_url' => $url, 'cta2_label' => 'Xem', 'cta2_url' => '/san-pham']);

    expect($html)->not->toContain('>Đi</a>')
        ->and($html)->toContain('href="/san-pham">Xem</a>')
        ->and($html)->not->toContain('<script>alert(1)')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
})->with(['javascript:alert(1)', '//evil.example/x', 'data:text/html,x']);

describe('on a page', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        // Print each Vite entry as a module script so the head's choices are visible.
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
            }
        });
    });

    /** @param list<array<string, mixed>> $blocks */
    function sliderPage(array $blocks): string
    {
        $siteId = StorefrontCards::site();
        app(Tenancy::class)->enter($siteId, function () use ($blocks): void {
            $ids = [];
            foreach (['a', 'b', 'c'] as $slug) {
                $ids[$slug] = StorefrontCards::media('slide-'.$slug)->id;
            }
            $blocks = json_decode(str_replace(['"@a"', '"@b"', '"@c"'], [(string) $ids['a'], (string) $ids['b'], (string) $ids['c']], (string) json_encode($blocks)), true);
            StorefrontCards::page('/trang', 'Cơ khí chính xác', $blocks);
        });

        return test()->get('http://mau.platform.test/trang')->assertOk()->getContent();
    }

    it('loads the slider script and a media preconnect, keeps the h1 and sizes slide 1 by srcset', function (): void {
        $html = sliderPage([['type' => 'hero_slider', 'props' => ['slides' => [
            ['image_url' => '@a', 'heading' => 'Một'], ['image_url' => '@b', 'heading' => 'Hai'], ['image_url' => '@c', 'heading' => 'Ba'],
        ], 'autoplay' => 5]]]);

        expect($html)->toContain('<script type="module" src="/resources/js/sf-slider.js"></script>')
            ->and($html)->toContain('<link rel="preconnect" href="https://files.test">')
            ->and($html)->toContain('<h1 class="page__title">Cơ khí chính xác</h1>')
            ->and($html)->toMatch('#src="https://files\.test/s/library/slide-a-[0-9a-f]{12}\.webp\?w=1280" srcset="https://files\.test/s/library/slide-a-[0-9a-f]{12}\.webp\?w=640 640w, [^"]+1600w"#')
            ->and($html)->not->toMatch('#<script>(?!\s*$)#');
    });

    it('loads no slider script for one slide, but still preconnects for its photo', function (): void {
        $html = sliderPage([['type' => 'hero_slider', 'props' => ['slides' => [['image_url' => '@a', 'heading' => 'Một']], 'autoplay' => 5]]]);

        expect($html)->not->toContain('sf-slider.js')->and($html)->toContain('<link rel="preconnect" href="https://files.test">');
    });

    it('loads no slider script and no preconnect without a leading slider', function (array $blocks): void {
        $html = sliderPage($blocks);

        expect($html)->not->toContain('sf-slider.js')->and($html)->not->toContain('rel="preconnect"');
    })->with([
        'no slider' => [[['type' => 'hero', 'props' => ['heading' => 'Một', 'image_url' => '@a']]]],
        'a band leads' => [[['type' => 'page_header', 'props' => ['image_url' => '@a']], ['type' => 'richtext', 'props' => ['paragraphs' => ['x']]]]],
    ]);

    it('preconnects only when the leading slider starts with a photo', function (): void {
        $html = sliderPage([['type' => 'hero_slider', 'props' => ['slides' => [['heading' => 'Chỉ chữ'], ['image_url' => '@b', 'heading' => 'Hai']]]]]);

        expect($html)->toContain('sf-slider.js')->and($html)->not->toContain('rel="preconnect"');
    });
});
