<?php

use App\Modules\Theme\Components\ListDisplay;
use App\Modules\Theme\Components\SectionProps;
use App\Modules\Theme\Components\StyleProps;

/*
 * The display group of a list block: one table of what "auto" means per kind of list, every stored
 * value, a stored value that is not in its list reading as "auto", and a block saved before the group
 * (no `layout` key) being left alone.
 */

it('names no prop the look group or its editor state already owns', function (): void {
    expect(array_intersect(array_keys(SectionProps::LIST_DISPLAY), StyleProps::RESERVED))->toBe([]);
});

it('declares the six props with "auto" first and as the default, and keeps them out of the generic form', function (): void {
    expect(array_keys(SectionProps::LIST_DISPLAY))->toBe(['layout', 'columns', 'mobile', 'autoplay', 'arrows', 'view_all']);
    foreach (SectionProps::LIST_DISPLAY as $spec) {
        expect($spec['type'])->toBe('enum')->and($spec['options'][0])->toBe('auto')->and($spec['default'])->toBe('auto')->and($spec['group'])->toBe('display');
    }
    expect(SectionProps::LIST_DISPLAY['layout']['options'])->toBe(['auto', 'grid', 'slider'])
        ->and(SectionProps::LIST_DISPLAY['columns']['options'])->toBe(['auto', '2', '3', '4'])
        ->and(SectionProps::LIST_DISPLAY['mobile']['options'])->toBe(['auto', 'swipe', 'two', 'one'])
        ->and(SectionProps::LIST_DISPLAY['autoplay']['options'])->toBe(['auto', 'off', '3', '5', '8'])
        ->and(SectionProps::LIST_DISPLAY['arrows']['options'])->toBe(['auto', 'always', 'none'])
        ->and(SectionProps::LIST_DISPLAY['view_all']['options'])->toBe(['auto', 'show', 'hide']);
});

it('leaves a block without a layout key alone', function (): void {
    expect(ListDisplay::plan('news', ['limit' => 3], 3, 3))->toBeNull()
        ->and(ListDisplay::plan('news', ['limit' => 3, 'columns' => '4'], 3, 3))->toBeNull()
        ->and(ListDisplay::applies(['layout' => 'auto']))->toBeTrue();
});

it('resolves "auto" per kind of list from one table', function (string $kind, int $count, array $expect): void {
    $plan = ListDisplay::plan($kind, ['layout' => 'auto'], $count, 50);

    expect($plan)->toMatchArray($expect);
})->with([
    'product overlay, six' => ['product-overlay', 6, ['layout' => 'grid', 'columns' => 3, 'mobile' => 'swipe', 'interval' => 0, 'arrows' => 'auto']],
    'product overlay, seven' => ['product-overlay', 7, ['layout' => 'slider', 'columns' => 3, 'interval' => 5000]],
    'product below the photo, eight' => ['product', 8, ['layout' => 'grid', 'columns' => 4]],
    'product below the photo, nine' => ['product', 9, ['layout' => 'slider', 'columns' => 4, 'interval' => 5000]],
    'service icons' => ['icon', 20, ['layout' => 'grid', 'columns' => 4, 'interval' => 0]],
    'project cards, four' => ['project', 4, ['layout' => 'grid', 'columns' => 4, 'arrows' => 'always']],
    'project cards, five' => ['project', 5, ['layout' => 'slider', 'columns' => 4, 'arrows' => 'always', 'interval' => 5000]],
    'news' => ['news', 3, ['layout' => 'grid', 'columns' => 3, 'interval' => 0]],
    'partners, six' => ['partners', 6, ['layout' => 'grid', 'columns' => 6]],
    'partners, seven' => ['partners', 7, ['layout' => 'slider', 'columns' => 6, 'interval' => 3000, 'arrows' => 'always']],
    'testimonials, three' => ['testimonial', 3, ['layout' => 'grid', 'columns' => 3]],
    'testimonials, four' => ['testimonial', 4, ['layout' => 'slider', 'columns' => 3, 'interval' => 8000]],
    'team' => ['team', 9, ['layout' => 'grid', 'columns' => 4, 'interval' => 0]],
]);

it('puts a phone on one column for one or two items and on a swipe row from three', function (): void {
    expect(ListDisplay::plan('news', ['layout' => 'auto'], 2, 3)['mobile'])->toBe('one')
        ->and(ListDisplay::plan('news', ['layout' => 'auto'], 3, 3)['mobile'])->toBe('swipe');
});

it('honours every stored value of every prop', function (): void {
    $plan = fn (array $props, int $count = 10): array => ListDisplay::plan('news', $props + ['layout' => 'auto'], $count, 50);

    expect($plan(['layout' => 'slider'])['layout'])->toBe('slider')
        ->and($plan(['layout' => 'grid'], 40)['layout'])->toBe('grid');
    foreach (['2', '3', '4'] as $columns) {
        expect($plan(['columns' => $columns])['columns'])->toBe((int) $columns)
            ->and($plan(['columns' => $columns])['class'])->toContain("sf-rail__list--c{$columns}");
    }
    foreach (['swipe', 'two', 'one'] as $mobile) {
        expect($plan(['mobile' => $mobile])['mobile'])->toBe($mobile)
            ->and($plan(['mobile' => $mobile])['class'])->toContain("sf-rail__list--m-{$mobile}");
    }
    expect($plan(['layout' => 'slider', 'autoplay' => 'off'])['interval'])->toBe(0);
    foreach (['3', '5', '8'] as $seconds) {
        expect($plan(['layout' => 'slider', 'autoplay' => $seconds])['interval'])->toBe((int) $seconds * 1000);
    }
    expect($plan(['arrows' => 'always'])['arrows'])->toBe('always')
        ->and($plan(['arrows' => 'none'])['arrows'])->toBe('none')
        ->and($plan(['arrows' => 'auto'])['arrows'])->toBe('auto');
});

it('reads a value that is not in its list as "auto"', function (): void {
    $odd = ListDisplay::plan('project', ['layout' => 'carousel', 'columns' => '9', 'mobile' => 'wide', 'autoplay' => '1', 'arrows' => 'hover', 'view_all' => 'maybe'], 5, 50);
    $auto = ListDisplay::plan('project', ['layout' => 'auto'], 5, 50);

    expect($odd)->toBe($auto);
});

it('never rotates faster than three seconds and never rotates a grid or a one-page slider', function (): void {
    expect(ListDisplay::plan('news', ['layout' => 'slider', 'autoplay' => '3'], 9, 50)['interval'])->toBe(3000)
        ->and(ListDisplay::plan('news', ['layout' => 'grid', 'autoplay' => '5'], 9, 50)['interval'])->toBe(0)
        ->and(ListDisplay::plan('news', ['layout' => 'slider', 'autoplay' => '5'], 3, 50)['interval'])->toBe(0);
});

it('shows "view all" only when a row beyond the limit exists, unless the block says show or hide', function (): void {
    $viewAll = fn (array $props, int $found, int $limit): bool => ListDisplay::plan('news', $props + ['layout' => 'auto'], $found, $limit)['view_all'];

    expect($viewAll([], 6, 6))->toBeFalse()
        ->and($viewAll([], 7, 6))->toBeTrue()
        ->and($viewAll(['view_all' => 'hide'], 7, 6))->toBeFalse()
        ->and($viewAll(['view_all' => 'show'], 3, 6))->toBeTrue();
});

it('draws at most 24 items in a slider and says there is more when it cut some', function (): void {
    $slider = ListDisplay::plan('news', ['layout' => 'slider'], 31, 50);
    $grid = ListDisplay::plan('news', ['layout' => 'grid'], 31, 50);

    expect($slider['shown'])->toBe(24)->and($slider['view_all'])->toBeTrue()
        ->and($grid['shown'])->toBe(31)->and($grid['view_all'])->toBeFalse()
        ->and(ListDisplay::LIMIT_MAX)->toBe(50);
});

it('holds two rows of the resolved columns in a product grid before it becomes a slider', function (string $kind, array $props, int $count, string $layout): void {
    expect(ListDisplay::plan($kind, ['layout' => 'auto'] + $props, $count, 50)['layout'])->toBe($layout);
})->with([
    'overlay, auto columns, six' => ['product-overlay', [], 6, 'grid'],
    'overlay, auto columns, seven' => ['product-overlay', [], 7, 'slider'],
    'overlay, two columns, four' => ['product-overlay', ['columns' => '2'], 4, 'grid'],
    'overlay, two columns, five' => ['product-overlay', ['columns' => '2'], 5, 'slider'],
    'product, three columns, six' => ['product', ['columns' => '3'], 6, 'grid'],
    'product, three columns, seven' => ['product', ['columns' => '3'], 7, 'slider'],
    'product, four columns, eight' => ['product', ['columns' => '4'], 8, 'grid'],
]);

it('makes partner logos a rotating three-logo slider on a phone, even when the wide layout is a grid', function (): void {
    $grid = ListDisplay::plan('partners', ['layout' => 'auto'], 5, PHP_INT_MAX);
    $slider = ListDisplay::plan('partners', ['layout' => 'auto'], 8, PHP_INT_MAX);
    $three = ListDisplay::plan('partners', ['layout' => 'auto'], 3, PHP_INT_MAX);
    $off = ListDisplay::plan('partners', ['layout' => 'auto', 'autoplay' => 'off'], 5, PHP_INT_MAX);
    $swipe = ListDisplay::plan('partners', ['layout' => 'auto', 'mobile' => 'swipe'], 5, PHP_INT_MAX);

    expect($grid)->toMatchArray(['layout' => 'grid', 'mobile' => 'slider', 'interval' => 3000, 'bar' => 'phone'])
        ->and($grid['class'])->toContain('sf-rail__list--m-slider')
        ->and($slider)->toMatchArray(['layout' => 'slider', 'mobile' => 'slider', 'interval' => 3000, 'bar' => '', 'scrolls' => true])
        ->and($three['interval'])->toBe(0)
        ->and($off['interval'])->toBe(0)
        ->and($swipe['mobile'])->toBe('swipe')->and($swipe['interval'])->toBe(0)
        ->and(ListDisplay::plan('news', ['layout' => 'auto'], 9, 50)['mobile'])->toBe('swipe');
});

it('keeps the Pause bar only on the widths where the list rotates', function (): void {
    $wideOnly = ListDisplay::plan('news', ['layout' => 'slider'], 9, 50);
    $always = ListDisplay::plan('news', ['layout' => 'slider', 'mobile' => 'two'], 9, 50);
    $none = ListDisplay::plan('news', ['layout' => 'grid'], 9, 50);

    $fewLogos = ListDisplay::plan('partners', ['layout' => 'slider', 'columns' => '2'], 3, PHP_INT_MAX);

    expect($fewLogos)->toMatchArray(['interval' => 3000, 'bar' => 'wide'])
        ->and($wideOnly['bar'])->toBe('wide')->and($always['bar'])->toBe('')->and($none['interval'])->toBe(0)->and($none['scrolls'])->toBeFalse();
});
